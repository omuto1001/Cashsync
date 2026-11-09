<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\NodeEvent;
use App\Models\NodeState;
use App\Models\Transaction;

/**
 * The bank server's live dashboard: http://127.0.0.1:8001/dashboard (A)
 * and http://127.0.0.1:8002/dashboard (B). The page reloads itself every 2 seconds.
 */
class DashboardController extends Controller
{
    public function show()
    {
        $state = NodeState::current();

        // The other server counts as "alive" if its last heartbeat answer was under 5 seconds ago
        $peerAlive = $state->peer_seen_at && $state->peer_seen_at->gt(now()->subSeconds(5));

        return view('bank.dashboard', [
            'state' => $state,                                              // role, epoch, degraded
            'peerAlive' => $peerAlive,
            'accounts' => Account::orderBy('account_no')->get(),            // every account
            'transactions' => Transaction::latest('id')->limit(20)->get(),  // 20 newest (UUIDs are time-ordered)
            'events' => NodeEvent::latest('id')->limit(12)->get(),          // 12 newest events
            'total' => Account::sum('balance'),                             // money held in all accounts
            'count' => Transaction::count(),
        ]);
    }
}