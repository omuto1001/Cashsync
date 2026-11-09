<?php

namespace App\Http\Controllers;

use App\Services\BankService;
use App\Services\NodeManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The bank server's HTTP API. Every reply is JSON.
 *
 *  Called by ATMs:              withdraw, deposit, transfer, balance
 *  Called by the test tools:    adjust (needs the admin key)
 *  Called by the OTHER bank:    replicate, health, snapshot
 */
class BankApiController extends Controller
{
    // Laravel hands us the BankService (Step 4) and the NodeManager (Step 5)
    public function __construct(private BankService $bank, private NodeManager $node) {}

    // ---------- Money-changing requests from the ATMs ----------

    public function withdraw(Request $r): JsonResponse
    {
        return $this->write('withdraw', $r);
    }

    public function deposit(Request $r): JsonResponse
    {
        return $this->write('deposit', $r);
    }

    public function transfer(Request $r): JsonResponse
    {
        return $this->write('transfer', $r);
    }

    /** Test tools only: set an account's balance. Refused without the secret admin key. */
    public function adjust(Request $r): JsonResponse
    {
        abort_unless($r->header('X-Admin-Key') === config('cashsync.admin_key'), 403, 'Wrong admin key');
        $r->validate(['new_balance' => 'required|integer|min:0']);

        return $this->write('adjust', $r);
    }

    /** Shared by withdraw / deposit / transfer / adjust. */
    private function write(string $type, Request $r): JsonResponse
    {
        // Check the request is complete and well-formed before touching any money.
        // If a rule fails, Laravel automatically replies with HTTP 422 and the reason.
        $in = $r->validate([
            'request_id' => 'required|uuid',                 // every request MUST carry a UUID
            'atm_id' => 'nullable|string|max:20',
            'account_no' => 'required|string|max:20',
            'to_account_no' => $type === 'transfer' ? 'required|string|max:20' : 'nullable',
            'pin' => $type === 'adjust' ? 'nullable' : 'required|string',
            'amount' => $type === 'adjust' ? 'nullable' : 'required|integer',
            'new_balance' => 'nullable|integer',
        ]);

        // The real work happens in BankService (locks, rules, replication)
        [$status, $body] = $this->bank->write($type, $in);

        // DEMO ONLY ("simulate a lost reply" tick box on the ATM):
        // the work is done and saved, but we hold the reply back for longer than the ATM
        // is willing to wait, exactly as if the reply were lost on a slow network.
        if ($status === 200 && $r->header('X-Simulate-Lost-Reply') === '1') {
            usleep((int) ((config('cashsync.atm_timeout') + 1.5) * 1000000));
        }

        return response()->json($body, $status);
    }

    /** Balance and mini-statement (also used by the ATM to check the PIN at login). */
    public function balance(Request $r): JsonResponse
    {
        [$status, $body] = $this->bank->balance($r->only('account_no', 'pin'));

        return response()->json($body, $status);
    }

    // ---------- Called by the OTHER bank server ----------

    /** The primary sends us a copy of a transaction; we save it and reply with an "ack". */
    public function replicate(Request $r): JsonResponse
    {
        [$status, $body] = $this->node->applyReplica($r->all());

        return response()->json($body, $status);
    }

    /** Heartbeat: "are you alive, what is your role and epoch?" */
    public function health(): JsonResponse
    {
        return response()->json($this->node->health());
    }

    /** Everything a returning server needs to catch up. */
    public function snapshot(): JsonResponse
    {
        return response()->json($this->node->snapshot());
    }
}