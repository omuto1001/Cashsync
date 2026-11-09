{{-- One screen for withdraw, deposit and transfer ($type says which). --}}
@extends('atm.frame', ['keys' => [
    'l2' => ['label' => 'Cancel', 'href' => route('menu')],
    'r2' => ['label' => 'Confirm', 'submit' => 'atm-form'],
]])
@section('title', ['withdraw' => 'Withdraw cash', 'deposit' => 'Deposit', 'transfer' => 'Transfer'][$type])
@section('screen')
    <h1>{{ ['withdraw' => 'Withdraw cash', 'deposit' => 'Deposit', 'transfer' => 'Transfer money'][$type] }}</h1>
    <form id="atm-form" method="post" action="{{ route('submit', $type) }}">@csrf
        @if($type === 'transfer')
            <label for="to_account_no">To account number</label>
            <input id="to_account_no" type="text" name="to_account_no" value="{{ old('to_account_no') }}" inputmode="numeric" required>
        @endif
        <label for="amount">Amount in shillings</label>
        <input id="amount" type="number" name="amount" min="1000" step="1000" value="{{ old('amount') }}" required>
        @if($type === 'withdraw')
            <div class="quick">
                @foreach([20000, 50000, 100000, 200000, 500000] as $q)
                    <button type="button" onclick="document.getElementById('amount').value={{ $q }}">{{ number_format($q) }}</button>
                @endforeach
            </div>
        @endif
        {{-- DEMO tick box: the bank does the work but holds back its reply, so the ATM must retry --}}
        <label class="demo"><input type="checkbox" name="simulate_lost_reply" value="1">
            Demo: lose the bank's first reply, so the ATM has to retry</label>
    </form>
@endsection