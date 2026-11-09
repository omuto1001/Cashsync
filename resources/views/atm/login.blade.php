{{-- Welcome screen: choose the machine, enter account number and PIN. --}}
@extends('atm.frame', ['keys' => ['r2' => ['label' => 'Continue', 'submit' => 'atm-form']]])
@section('screen')
    <h1>Welcome</h1>
    <p class="prompt">Enter your account number and PIN</p>
    <form id="atm-form" method="post" action="{{ route('login') }}">@csrf
        <label for="atm_id">This machine</label>
        <select id="atm_id" name="atm_id">
            <option value="ATM1" @selected(old('atm_id', $atm) === 'ATM1')>ATM1, Kampala Road</option>
            <option value="ATM2" @selected(old('atm_id', $atm) === 'ATM2')>ATM2, Mbarara branch</option>
        </select>
        <label for="account_no">Account number</label>
        <input id="account_no" type="text" name="account_no" value="{{ old('account_no') }}" inputmode="numeric" required autofocus>
        <label for="pin">PIN</label>
        <input id="pin" type="password" name="pin" inputmode="numeric" required>
    </form>
    <p style="font-size:13px;margin-top:14px;opacity:.8">Demo accounts 1001, 1002, 2002 and 3003 use PIN 1234. Test account 9999 uses PIN 0000.</p>
@endsection
@if(session('attempts'))
    @section('receipt')
        @include('atm.receipt', ['lines' => [['Login', 'not accepted']], 'attempts' => session('attempts')])
    @endsection
@endif