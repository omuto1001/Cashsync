<?php

namespace App\Services;

use App\Models\Account;
use App\Models\NodeEvent;
use App\Models\NodeState;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * NodeManager: manages THIS server's role and keeps the two copies of the data the same.
 *
 *  - promote():       backup  -> primary  (when the primary has died: failover)
 *  - stepDown():      primary -> backup   (when a newer primary exists: fencing)
 *  - applyReplica():  the backup saves one copy sent by the primary
 *  - snapshot() / applySnapshot(): a returning server copies everything it missed
 */
class NodeManager
{
    /** A short status report. The other server asks for it every second (heartbeat). */
    public function health(): array
    {
        $state = NodeState::current();

        return [
            'node' => config('cashsync.node_name'),
            'role' => $state->role,
            'epoch' => $state->epoch,
            'txn_count' => Transaction::count(), // lets the other server see if it is missing transactions
            'time' => now()->toIso8601String(),
        ];
    }

    /**
     * FAILOVER: the backup becomes the primary.
     * Its new epoch is higher than any epoch seen so far, so everyone can tell the
     * new primary from the old one.
     */
    public function promote(string $why): void
    {
        DB::transaction(function () use ($why) {
            $state = NodeState::query()->lockForUpdate()->first();
            if ($state->isPrimary()) {
                return; // already primary, nothing to do
            }
            $newEpoch = max($state->epoch, (int) $state->peer_epoch) + 1;
            // degraded = true: right now there is no backup confirming our copies
            $state->update(['role' => 'primary', 'epoch' => $newEpoch, 'degraded' => true]);
            NodeEvent::log("PROMOTED to primary (epoch {$newEpoch}): {$why}", 'success');
        });
    }

    /**
     * FENCING: the primary hands over and becomes the backup, because it found
     * another primary with a newer epoch. (This is how a returning server A
     * becomes the backup.)
     */
    public function stepDown(int $newerEpoch, string $why): void
    {
        DB::transaction(function () use ($newerEpoch, $why) {
            $state = NodeState::query()->lockForUpdate()->first();
            $epoch = max($state->epoch, $newerEpoch);
            if (! $state->isPrimary() && $state->epoch >= $epoch) {
                return; // already a backup and already up to date
            }
            $state->update(['role' => 'backup', 'epoch' => $epoch, 'degraded' => false]);
            NodeEvent::log("STEPPED DOWN to backup (epoch {$epoch}): {$why}", 'warning');
        });
    }

    /** Remember whether the last copy to the backup worked ("degraded" on the dashboard). */
    public function markReplication(bool $ok): void
    {
        $state = NodeState::current();
        if ($state->degraded === $ok) { // only log when the situation CHANGES
            $state->update(['degraded' => ! $ok]);
            NodeEvent::log($ok
                ? 'Backup confirmed copies again: fully replicated.'
                : 'Backup did not confirm a copy: running DEGRADED (no backup).', $ok ? 'success' : 'warning');
        }
    }

    /**
     * BACKUP SIDE: save one transaction copied from the primary.
     * Returns [HTTP status, body]. 409 means "refused: you are an out-of-date primary".
     */
    public function applyReplica(array $payload): array
    {
        $incomingEpoch = (int) ($payload['epoch'] ?? 0);

        return DB::transaction(function () use ($payload, $incomingEpoch) {
            $state = NodeState::query()->lockForUpdate()->first();

            // FENCING: refuse copies from an OLD primary (lower epoch than ours),
            // and refuse if we are ourselves primary with the same epoch.
            if ($incomingEpoch < $state->epoch || ($state->isPrimary() && $incomingEpoch === $state->epoch)) {
                return [409, ['ok' => false, 'error' => 'fenced', 'epoch' => $state->epoch, 'role' => $state->role]];
            }
            if ($state->isPrimary()) {
                // A primary with a NEWER epoch exists, so we must be the backup now.
                $state->update(['role' => 'backup', 'degraded' => false]);
                NodeEvent::log("STEPPED DOWN to backup: received a copy from epoch {$incomingEpoch}", 'warning');
            }
            // Remember what we know about the primary
            $state->update(['epoch' => $incomingEpoch, 'peer_status' => 'up', 'peer_role' => 'primary',
                'peer_epoch' => $incomingEpoch, 'peer_seen_at' => now()]);

            // Save the copy: the transaction, then the changed accounts
            $this->storeTransactions([$payload['transaction']]);
            $this->storeAccounts($payload['accounts'] ?? []);

            // 200 OK = the "ack" the primary is waiting for
            return [200, ['ok' => true, 'node' => config('cashsync.node_name'), 'epoch' => $state->epoch]];
        });
    }

    /** PRIMARY SIDE: everything a returning server needs to catch up (all accounts + all transactions). */
    public function snapshot(): array
    {
        return DB::transaction(fn () => [
            'epoch' => NodeState::current()->epoch,
            'accounts' => Account::all()->map(fn ($a) => $a->makeVisible('pin_hash')->toArray())->all(),
            'transactions' => Transaction::orderBy('id')->get()->toArray(),
        ]);
    }

    /** BACKUP SIDE: apply a snapshot. Returns how many transactions were new to us. */
    public function applySnapshot(array $snap): int
    {
        return DB::transaction(function () use ($snap) {
            $before = Transaction::count();
            $this->storeTransactions($snap['transactions'] ?? []);
            $this->storeAccounts($snap['accounts'] ?? []);

            return Transaction::count() - $before;
        });
    }

    /** Save transactions with the SAME UUIDs as on the other server; skip ones we already have. */
    private function storeTransactions(array $rows): void
    {
        $cols = ['id', 'request_id', 'atm_id', 'type', 'account_no', 'to_account_no', 'amount', 'status', 'message',
            'balance_after', 'to_balance_after', 'epoch', 'processed_by', 'response', 'created_at', 'updated_at'];

        foreach (array_chunk($rows, 200) as $chunk) {             // 200 rows at a time
            $clean = array_map(function ($r) use ($cols) {
                $r = array_intersect_key($r, array_flip($cols));     // keep only real columns
                foreach (['created_at', 'updated_at'] as $c) {       // convert dates to MySQL format
                    $r[$c] = isset($r[$c])
                        ? Carbon::parse($r[$c])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s')
                        : now();
                }

                return $r;
            }, $chunk);
            // insertOrIgnore: rows whose UUID or request_id we already have are skipped
            Transaction::insertOrIgnore($clean);
        }
    }

    /** Save account copies, but ONLY if the copy is newer (higher version) than ours. */
    private function storeAccounts(array $rows): void
    {
        foreach ($rows as $a) {
            $existing = Account::where('account_no', $a['account_no'])->lockForUpdate()->first();
            if (! $existing) {
                // An account we did not have yet: create it with the same UUID
                Account::create(array_intersect_key($a, array_flip(['id', 'account_no', 'holder_name', 'pin_hash', 'balance', 'version'])));
            } elseif ((int) $a['version'] > $existing->version) {
                // Newer copy: take its balance. An OLDER copy is ignored, so an account
                // can only ever move forward in time, never back to an old balance.
                $existing->update(['balance' => $a['balance'], 'version' => $a['version'],
                    'holder_name' => $a['holder_name'], 'pin_hash' => $a['pin_hash'] ?? $existing->pin_hash]);
            }
        }
    }
}