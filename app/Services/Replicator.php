<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Facades\Http;

/**
 * Replicator: runs on the PRIMARY.
 * After every transaction it sends a copy to the backup over HTTP and waits for the
 * backup's "ack" (acknowledgement = "I have saved it").
 */
class Replicator
{
    // If the backup refuses us, this is the (newer) epoch it reported
    public int $lastPeerEpoch = 0;

    /**
     * @param  Transaction  $txn       the transaction just recorded
     * @param  array        $accounts  the accounts it changed, with their new balances and versions
     * @param  int          $epoch     our current epoch, so the backup can spot an out-of-date primary
     * @return string  "ok"     = the backup saved the copy
     *                 "down"   = the backup did not answer in time (it may have crashed)
     *                 "fenced" = the backup has a NEWER epoch: we are no longer the real primary
     */
    public function replicate(Transaction $txn, array $accounts, int $epoch): string
    {
        try {
            // POST the copy to the other server's /api/replicate address
            $response = Http::timeout(config('cashsync.replicate_timeout')) // max wait for the whole reply
                ->connectTimeout(1)                                          // max wait to connect
                ->acceptJson()
                ->post(config('cashsync.peer_url').'/api/replicate', [
                    'epoch' => $epoch,
                    'from' => config('cashsync.node_name'),
                    'transaction' => $txn->toArray(),
                    // makeVisible('pin_hash'): the backup needs the PIN hash too, so that
                    // customers can still log in if the backup takes over
                    'accounts' => array_map(fn ($a) => $a->makeVisible('pin_hash')->toArray(), $accounts),
                ]);
        } catch (\Throwable $e) {
            // No connection or no reply in time: the backup is down
            return 'down';
        }

        // 409 Conflict = the backup refused: "a newer primary exists"
        if ($response->status() === 409) {
            $this->lastPeerEpoch = (int) $response->json('epoch', 0);

            return 'fenced';
        }

        return $response->successful() ? 'ok' : 'down';
    }
}