<?php

namespace App\Console\Commands;

use App\Models\NodeEvent;
use App\Models\NodeState;
use App\Models\Transaction;
use App\Services\NodeManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * The HEARTBEAT. Runs in its own window next to each bank server:
 *     php artisan cashsync:heartbeat --env=bank_a
 *
 * Every second it asks the OTHER server "are you alive?" (GET /api/health), then:
 *  - BACKUP:  if the primary misses 3 heartbeats in a row -> take over (FAILOVER)
 *  - BACKUP:  if the primary has transactions we are missing -> copy them (CATCH-UP)
 *  - PRIMARY: if the other server is primary with a NEWER epoch -> step down (FENCING)
 * This is how a returning server A becomes the backup and copies what it missed from B.
 */
class Heartbeat extends Command
{
    // The command name typed after "php artisan"
    protected $signature = 'cashsync:heartbeat';

    protected $description = 'Send heartbeats to the other bank server and handle failover';

    public function handle(NodeManager $node): int
    {
        $me = config('cashsync.node_name');
        $peerUrl = config('cashsync.peer_url');
        $maxMisses = config('cashsync.heartbeat_misses');   // 3
        $misses = 0;                                         // heartbeats missed in a row

        $this->info("Heartbeat for server {$me}. Watching the other server at {$peerUrl}. Press Ctrl+C to stop.");

        while (true) {   // runs until you close the window or press Ctrl+C

            // A server "crashed" with cashsync:crash sends no heartbeats either
            if (Crash::isCrashed()) {
                $this->error('['.now()->format('H:i:s')."] {$me} is CRASHED (simulated). Run cashsync:recover to bring it back.");
                $misses = 0;
                sleep(1);

                continue;
            }

            $state = NodeState::current();
            $peer = $this->ping($peerUrl);   // the other server's health report, or null if no reply

            if ($peer) {
                // ---- The other server answered ----
                if ($misses >= $maxMisses) {
                    NodeEvent::log("Server {$peer['node']} is back ({$peer['role']}, epoch {$peer['epoch']}).", 'success');
                }
                $misses = 0;
                $state->update(['peer_status' => 'up', 'peer_role' => $peer['role'], 'peer_epoch' => $peer['epoch'], 'peer_seen_at' => now()]);
                $this->resolveRoles($node, $state->fresh(), $peer);
            } else {
                // ---- No answer ----
                $misses++;
                if ($misses === $maxMisses) {
                    $state->update(['peer_status' => 'down']);
                    NodeEvent::log("Server at {$peerUrl} missed {$maxMisses} heartbeats: it is DOWN.", 'danger');
                }
                // FAILOVER: we are the backup and the primary has been silent for 3 heartbeats
                if ($misses >= $maxMisses && ! $state->fresh()->isPrimary()) {
                    $node->promote("the primary missed {$misses} heartbeats");
                }
            }

            // Print one status line per second, e.g.
            // [21:40:01] B is PRIMARY (epoch 2) | other server: NO REPLY (5/3) | transactions: 4 | DEGRADED
            $s = NodeState::current();
            $line = sprintf('[%s] %s is %s (epoch %d) | other server: %s | transactions: %d%s',
                now()->format('H:i:s'), $me, strtoupper($s->role), $s->epoch,
                $peer ? 'UP, '.$peer['role'].' epoch '.$peer['epoch'] : 'NO REPLY ('.$misses.'/'.$maxMisses.')',
                Transaction::count(), $s->degraded ? ' | DEGRADED' : '');
            $peer ? $this->line($line) : $this->warn($line);

            usleep((int) (config('cashsync.heartbeat_interval') * 1000000));   // wait 1 second
        }
    }

    /** Ask the other server for its health report. Returns null if it does not answer within 0.9 s. */
    private function ping(string $url): ?array
    {
        try {
            $r = Http::timeout(0.9)->connectTimeout(0.9)->acceptJson()->get($url.'/api/health');

            return $r->successful() ? $r->json() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Decide who should be primary, now that we know the other server's role and epoch. */
    private function resolveRoles(NodeManager $node, NodeState $state, array $peer): void
    {
        $me = config('cashsync.node_name');
        $mine = Transaction::count();

        // Does the other server "win"? Higher epoch wins. Same epoch: more transactions wins.
        // Still equal: the server whose name comes first (A) wins.
        $peerWins = $peer['epoch'] > $state->epoch
            || ($peer['epoch'] === $state->epoch && $peer['txn_count'] > $mine)
            || ($peer['epoch'] === $state->epoch && $peer['txn_count'] === $mine && strcmp($peer['node'], $me) < 0);

        // 1. Two primaries (e.g. A came back after B took over): the loser steps down.
        if ($state->isPrimary() && $peer['role'] === 'primary' && $peerWins) {
            $node->stepDown($peer['epoch'], "server {$peer['node']} is primary with epoch {$peer['epoch']}");
            $state = $state->fresh();
        }

        // 2. Two backups (e.g. both restarted): the winner takes charge.
        if (! $state->isPrimary() && $peer['role'] === 'backup' && ! $peerWins) {
            $node->promote("both servers were backups; server {$me} has the most recent data");

            return;
        }

        // 3. We are primary and our backup is back with every transaction: no longer degraded.
        if ($state->isPrimary() && $peer['role'] === 'backup' && $state->degraded && $peer['txn_count'] >= $mine) {
            $node->markReplication(true);
        }

        // 4. We are the backup: follow the primary's epoch and copy anything we are missing.
        if (! $state->isPrimary() && $peer['role'] === 'primary') {
            if ($peer['epoch'] > $state->epoch) {
                $state->update(['epoch' => $peer['epoch']]);
            }
            if ($peer['txn_count'] > $mine) {
                $this->catchUp($node, $peer);
            }
        }
    }

    /** CATCH-UP: download everything from the primary and keep what we were missing. */
    private function catchUp(NodeManager $node, array $peer): void
    {
        try {
            $snap = Http::timeout(10)->acceptJson()->get(config('cashsync.peer_url').'/api/snapshot')->throw()->json();
            $added = $node->applySnapshot($snap);
            if ($added > 0) {
                NodeEvent::log("Caught up from server {$peer['node']}: copied {$added} missing transaction(s).", 'success');
            }
        } catch (\Throwable $e) {
            $this->warn('Catch-up failed: '.$e->getMessage());
        }
    }
}