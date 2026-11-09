<?php

namespace App\Http\Controllers;

use App\Services\BankClient;
use Illuminate\Http\Request;

/**
 * The ATM screens. The ATM has NO database: every action becomes a request to the bank,
 * sent through BankClient (which handles timeouts, retries and switching servers).
 * The logged-in customer is remembered in the browser SESSION.
 */
class AtmController extends Controller
{
    public function __construct(private BankClient $bank) {}

    /** The welcome / "insert card" screen. */
    public function home(Request $r)
    {
        if ($r->session()->has('account_no')) {
            return redirect()->route('menu');   // already logged in
        }

        return view('atm.login', ['atm' => $r->session()->get('atm_id', 'ATM1')]);
    }

    /** Log in: the bank checks the account number and PIN (via /api/balance). */
    public function login(Request $r)
    {
        $in = $r->validate([
            'atm_id' => 'required|in:ATM1,ATM2',
            'account_no' => 'required|string',
            'pin' => 'required|string',
        ]);
        $res = $this->bank->send('/api/balance', ['account_no' => $in['account_no'], 'pin' => $in['pin']]);

        if (! $res['ok'] || ($res['data']['status'] ?? '') !== 'approved') {
            return back()->withInput($r->only('atm_id', 'account_no'))
                ->with('error', $res['data']['message'] ?? 'Login failed.')
                ->with('attempts', $res['attempts']);
        }

        // Remember the customer for this ATM session (the "card in the machine")
        $r->session()->put([
            'atm_id' => $in['atm_id'],
            'account_no' => $in['account_no'],
            'pin' => $in['pin'],   // simulated card: kept only in this session
            'holder_name' => $res['data']['holder_name'],
        ]);

        return redirect()->route('menu');
    }

    /** "Return card": forget the customer (but remember which ATM this is). */
    public function logout(Request $r)
    {
        $atm = $r->session()->get('atm_id', 'ATM1');
        $r->session()->flush();
        $r->session()->put('atm_id', $atm);

        return redirect()->route('home')->with('info', 'Card returned. Thank you for using CashSync.');
    }

    /** The main menu. */
    public function menu(Request $r)
    {
        return $this->guard($r) ?? view('atm.menu');
    }

    /** Balance and mini-statement. */
    public function balance(Request $r)
    {
        if ($redirect = $this->guard($r)) {
            return $redirect;
        }
        $res = $this->bank->send('/api/balance', ['account_no' => session('account_no'), 'pin' => session('pin')]);

        return view('atm.balance', ['res' => $res]);
    }

    /** Show the withdraw / deposit / transfer form. */
    public function form(Request $r, string $type)
    {
        return $this->guard($r) ?? view('atm.form', ['type' => $type]);
    }

    /** The customer pressed "Confirm": send the request to the bank. */
    public function submit(Request $r, string $type)
    {
        if ($redirect = $this->guard($r)) {
            return $redirect;
        }
        $in = $r->validate([
            'amount' => 'required|integer|min:1000|max:10000000',
            'to_account_no' => $type === 'transfer' ? 'required|string' : 'nullable',
        ], ['amount.min' => 'The smallest amount is UGX 1,000.']);

        // ONE new request ID (a UUID) for this customer action.
        // Every retry of this action reuses it, so the bank can recognise repeats.
        $requestId = BankClient::newRequestId();

        $res = $this->bank->send('/api/'.$type, [
            'request_id' => $requestId,
            'atm_id' => session('atm_id'),
            'account_no' => session('account_no'),
            'pin' => session('pin'),
            'amount' => (int) $in['amount'],
            'to_account_no' => $in['to_account_no'] ?? null,
        ], $r->boolean('simulate_lost_reply'));   // the demo tick box

        // Show the result on the next page (flash = kept for one page only)
        return redirect()->route('result')->with('result', [
            'type' => $type,
            'request_id' => $requestId,
            'amount' => (int) $in['amount'],
            'res' => $res,
        ]);
    }

    /** The result screen ("Please take your cash"). */
    public function result(Request $r)
    {
        if ($redirect = $this->guard($r)) {
            return $redirect;
        }
        if (! session()->has('result')) {
            return redirect()->route('menu');
        }

        return view('atm.result', session('result'));
    }

    /** Send anyone who is not logged in back to the welcome screen. */
    private function guard(Request $r)
    {
        return $r->session()->has('account_no') ? null : redirect()->route('home');
    }
}