<?php

namespace App\Services;

use App\Exceptions\FencedException;
use App\Models\Account;
use App\Models\NodeState;
use App\Models\Transaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * BankService: the heart of a bank server.
 * It applies withdrawals, deposits and transfers SAFELY.
 *
 * For every request that changes money it:
 *   1. refuses if this server is not the primary (only ONE server may change balances)
 *   2. if the request ID was already processed, sends back the saved answer (safe retries)
 *   3. locks the account rows, lowest account number first (no two requests at once, no deadlocks)
 *   4. checks the rules: PIN, amount, enough balance, daily limit
 *   5. changes the balances and records the transaction, all inside ONE database transaction
 *      (all or nothing)
 *   6. copies the result to the backup and waits for its "ack" BEFORE committing and replying
 */
class BankService
{
    // Laravel automatically gives us a Replicator (copies to the backup) and a
    // NodeManager (handles primary/backup roles). We build both in Step 5.
    public function __construct(private Replicator $replicator, private NodeManager $node) {}

    /**
     * Handle one money-changing request: withdraw, deposit, transfer, or adjust (test tool).
     *
     * @return array{0:int,1:array} [HTTP status code, reply body]
     */
    public function write(string $type, array $in): array
    {
        // ---- Only the primary may change money ----
        $state = NodeState::current();
        if (! $state->isPrimary()) {
            // 503 + "not_primary" tells the ATM: try the other server
            return [503, $this->notPrimary($state)];
        }

        // ---- Safe retries: have we already processed this exact request ID? ----
        // If yes, send back exactly the same answer and change nothing.
        if ($saved = Transaction::where('request_id', $in['request_id'])->first()) {
            return [200, $this->replay($saved)];
        }

        try {
            // DB::transaction: everything inside apply() is saved together, or (if anything
            // fails or throws) nothing is saved at all. The account locks are held until it ends.
            $reply = DB::transaction(fn () => $this->apply($type, $in, $state));
        } catch (FencedException $e) {
            // The backup refused our copy because it has seen a NEWER epoch: another server
            // has become primary. The database transaction has been undone; we step down.
            $this->node->stepDown($e->newerEpoch, 'backup refused our copy (it has a newer epoch)');

            return [503, $this->notPrimary(NodeState::current())];
        } catch (QueryException $e) {
            // Two copies of the same request arrived at the same moment. The UNIQUE index on
            // request_id stopped the second one; we answer it with the first one's saved reply.
            if ($this->isDuplicateKey($e) && ($saved = Transaction::where('request_id', $in['request_id'])->first())) {
                return [200, $this->replay($saved)];
            }
            throw $e;
        }

        return [200, $reply];
    }

    /**
     * Runs INSIDE the database transaction. Locks, checks, changes, records, replicates.
     */
    private function apply(string $type, array $in, NodeState $state): array
    {
        $amount = (int) ($in['amount'] ?? 0);
        $from = (string) $in['account_no'];
        $to = $type === 'transfer' ? (string) ($in['to_account_no'] ?? '') : null;

        // ---- 1. LOCK the accounts involved ----
        // A transfer touches two accounts. We always lock the LOWER account number first.
        // If every request locks in the same order, two transfers can never each hold one
        // lock while waiting for the other's (that would be a deadlock).
        $numbers = array_values(array_unique(array_filter([$from, $to])));
        sort($numbers, SORT_STRING);
        $locked = [];
        foreach ($numbers as $no) {
            $query = Account::where('account_no', $no);
            // lockForUpdate() = SQL "SELECT ... FOR UPDATE": MySQL locks this account's row.
            // Any other request for the same account WAITS here until we commit.
            // (DEMO ONLY: with disable_locks=true we read without locking to show the bug.)
            $locked[$no] = config('cashsync.disable_locks') ? $query->first() : $query->lockForUpdate()->first();
        }
        $account = $locked[$from] ?? null;
        $toAccount = $to ? ($locked[$to] ?? null) : null;

        // ---- 2. CHECK the rules (null means "all good") ----
        $reason = $this->rejectReason($type, $in, $amount, $account, $to, $toAccount);

        if (config('cashsync.disable_locks') && $reason === null) {
            // DEMO ONLY: with no locks, wait a moment between "check" and "change"
            // so other requests can sneak in and the race condition becomes visible.
            usleep(config('cashsync.unsafe_delay_ms') * 1000);
        }

        // ---- 3. CHANGE the balances (only if approved) ----
        // decrement/increment run "balance = balance - amount" inside MySQL.
        $changed = [];
        if ($reason === null) {
            match ($type) {
                'withdraw' => $account->decrement('balance', $amount),
                'deposit' => $account->increment('balance', $amount),
                'transfer' => [$account->decrement('balance', $amount), $toAccount->increment('balance', $amount)],
                'adjust' => $account->update(['balance' => (int) $in['new_balance']]),
            };
            $changed = array_filter([$account, $toAccount]);
        }

        // ---- 4. RECORD the transaction (approved OR rejected), Laravel gives it a UUID ----
        // Rejected requests are recorded too, so a retry of a rejected request also gets the
        // same answer back.
        $txn = Transaction::create([
            'request_id' => $in['request_id'],
            'atm_id' => $in['atm_id'] ?? null,
            'type' => $type,
            'account_no' => $from,
            'to_account_no' => $to,
            'amount' => $type === 'adjust' ? (int) ($in['new_balance'] ?? 0) : $amount,
            'status' => $reason === null ? 'approved' : 'rejected',
            'message' => $reason ?? $this->successMessage($type, $amount),
            'epoch' => $state->epoch,
            'processed_by' => config('cashsync.node_name'),
            'response' => '{}',   // filled in just below, once we know the final balance
        ]);

        // Each changed account's VERSION goes up by 1.
        // The backup uses it to keep only the newest copy of each account.
        foreach ($changed as $acc) {
            $acc->increment('version');
            $acc->refresh();   // reload the new balance and version from the database
        }

        // The reply the ATM will receive (also saved, so a retry gets exactly the same reply)
        $reply = [
            'status' => $txn->status,
            'message' => $txn->message,
            'request_id' => $txn->request_id,
            'txn_id' => $txn->id,
            'type' => $type,
            'account_no' => $from,
            'to_account_no' => $to,
            'amount' => $amount,
            'balance' => $account?->refresh()->balance,
            'epoch' => $state->epoch,
            'server' => config('cashsync.node_name'),
            'replayed' => false,
        ];

        $txn->update([
            'balance_after' => $reply['balance'],
            'to_balance_after' => $toAccount && $reason === null ? $toAccount->balance : null,
            'response' => json_encode($reply),
        ]);

        // ---- 5. REPLICATE: copy to the backup and wait for its "ack" BEFORE committing ----
        // "ok"     = the backup has saved the same transaction and balances
        // "down"   = the backup did not answer; we carry on alone ("degraded")
        // "fenced" = the backup has a newer epoch: we are an OLD primary, so undo everything
        $result = $this->replicator->replicate($txn->fresh(), array_values($changed), $state->epoch);
        if ($result === 'fenced') {
            throw new FencedException($this->replicator->lastPeerEpoch); // undoes the DB transaction
        }
        $this->node->markReplication($result === 'ok');

        return $reply;   // leaving the function COMMITS the transaction and RELEASES the locks
    }

    /**
     * The bank's rules. Returns the reason for refusing, or null if everything is fine.
     */
    private function rejectReason(string $type, array $in, int $amount, ?Account $account, ?string $to, ?Account $toAccount): ?string
    {
        if (! $account) {
            return 'Account not found.';
        }
        if ($type === 'adjust') {
            return null; // test tool: the controller already checked the admin key
        }
        // Hash::check compares the typed PIN with the stored hash
        if (! Hash::check((string) ($in['pin'] ?? ''), $account->pin_hash)) {
            return 'Incorrect PIN.';
        }
        if ($amount <= 0) {
            return 'Amount must be more than zero.';
        }
        if ($type === 'transfer') {
            if (! $toAccount) {
                return 'Destination account not found.';
            }
            if ($to === $account->account_no) {
                return 'Cannot transfer to the same account.';
            }
        }
        // Because the account is LOCKED, this balance cannot change under our feet
        if (in_array($type, ['withdraw', 'transfer'], true) && $account->balance < $amount) {
            return 'Insufficient funds.';
        }
        // Daily limit: add up today's approved withdrawals from ALL ATMs for this account
        if ($type === 'withdraw') {
            $today = Transaction::where('account_no', $account->account_no)
                ->where('type', 'withdraw')->where('status', 'approved')
                ->where('created_at', '>=', now()->startOfDay())
                ->sum('amount');
            if ($today + $amount > config('cashsync.daily_limit')) {
                return 'Daily withdrawal limit reached (UGX '.number_format(config('cashsync.daily_limit')).').';
            }
        }

        return null;
    }

    /** The message the customer sees when a request is approved. */
    private function successMessage(string $type, int $amount): string
    {
        $ugx = 'UGX '.number_format($amount);

        return match ($type) {
            'withdraw' => "Withdrawal of {$ugx} approved. Please take your cash.",
            'deposit' => "Deposit of {$ugx} received.",
            'transfer' => "Transfer of {$ugx} completed.",
            'adjust' => 'Balance set by admin.',
            default => 'Done.',
        };
    }

    /**
     * Read-only request: check the PIN, return the balance and the last 5 transactions.
     * Also used by the ATM to log a customer in.
     */
    public function balance(array $in): array
    {
        $state = NodeState::current();
        // Reads also go to the primary only, so the customer always sees the latest balance
        if (! $state->isPrimary()) {
            return [503, $this->notPrimary($state)];
        }
        $account = Account::where('account_no', $in['account_no'] ?? '')->first();
        if (! $account || ! Hash::check((string) ($in['pin'] ?? ''), $account->pin_hash)) {
            return [200, ['status' => 'rejected', 'message' => 'Incorrect account number or PIN.', 'server' => config('cashsync.node_name')]];
        }

        // Mini-statement: the 5 most recent approved money movements on this account
        $recent = Transaction::where('status', 'approved')
            ->where(fn ($q) => $q->where('account_no', $account->account_no)->orWhere('to_account_no', $account->account_no))
            ->whereIn('type', ['withdraw', 'deposit', 'transfer'])
            ->latest('id')->limit(5)->get()   // UUIDs are time-ordered, so latest('id') = newest first
            ->map(fn ($t) => [
                'date' => $t->created_at->format('d M H:i'),
                'type' => $t->type === 'transfer'
                    ? ($t->account_no === $account->account_no ? 'Transfer to '.$t->to_account_no : 'Transfer from '.$t->account_no)
                    : ucfirst($t->type),
                // money in = positive, money out = negative
                'amount' => ($t->type === 'deposit' || ($t->type === 'transfer' && $t->to_account_no === $account->account_no)) ? $t->amount : -$t->amount,
            ]);

        return [200, [
            'status' => 'approved',
            'holder_name' => $account->holder_name,
            'account_no' => $account->account_no,
            'balance' => $account->balance,
            'recent' => $recent,
            'epoch' => $state->epoch,
            'server' => config('cashsync.node_name'),
        ]];
    }

    /** Build the reply for a repeated request from the saved original reply. */
    private function replay(Transaction $t): array
    {
        $reply = json_decode($t->response, true) ?: [];
        $reply['replayed'] = true;                       // tells the ATM: repeat, nothing charged again
        $reply['server'] = config('cashsync.node_name'); // which server answered this time

        return $reply;
    }

    /** The reply a backup gives when someone asks it to do the primary's job. */
    private function notPrimary(NodeState $state): array
    {
        return [
            'status' => 'error',
            'error' => 'not_primary',
            'message' => 'Server '.config('cashsync.node_name').' is the '.$state->role.', not the primary.',
            'role' => $state->role,
            'epoch' => $state->epoch,
            'server' => config('cashsync.node_name'),
        ];
    }

    /** MySQL error 1062 = "Duplicate entry" (a UNIQUE index refused the row). */
    private function isDuplicateKey(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062 || str_contains($e->getMessage(), 'Duplicate entry');
    }
}