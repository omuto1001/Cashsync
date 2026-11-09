{{-- Main menu: the four services sit next to the four side buttons. --}}
@extends('atm.frame', ['keys' => [
    'l1' => ['label' => 'Withdraw cash', 'href' => route('withdraw')],
    'l2' => ['label' => 'Deposit', 'href' => route('deposit')],
    'r1' => ['label' => 'Balance', 'href' => route('balance')],
    'r2' => ['label' => 'Transfer', 'href' => route('transfer')],
]])
@section('title', 'Choose a service')
@section('screen')
    <h1>Hello {{ explode(' ', session('holder_name'))[0] }}</h1>
    <p class="prompt">Choose a service</p>
    <p style="opacity:.8">Account {{ session('account_no') }}</p>
@endsection