{{-- Balance on the screen; the mini-statement is printed on the receipt. --}}
@php($d = $res['data'])
@php($ok = $res['ok'] && ($d['status'] ?? '') === 'approved')
@extends('atm.frame', ['keys' => ['r2' => ['label' => 'Back to services', 'href' => route('menu')]]])
@section('title', 'Balance')
@section('screen')
    @if($ok)
        <h1>Your balance</h1>
        <div class="big">UGX {{ number_format($d['balance']) }}</div>
        <p>Your mini-statement is on the receipt below.</p>
    @else
        <h1>Balance not available</h1>
        <p class="prompt">{{ $d['message'] ?? 'The bank did not answer. Please try again.' }}</p>
    @endif
@endsection
@section('receipt')
    @include('atm.receipt', [
        'lines' => $ok
            ? array_merge(
                [['Account', $d['account_no']], ['Balance', 'UGX '.number_format($d['balance'])], ['', '']],
                collect($d['recent'])->map(fn ($t) => [$t['date'].' '.$t['type'], ($t['amount'] < 0 ? '-' : '+').number_format(abs($t['amount']))])->all() ?: [['No transactions yet', '']]
              )
            : [['Balance', 'not available']],
        'attempts' => $res['attempts'],
    ])
@endsection