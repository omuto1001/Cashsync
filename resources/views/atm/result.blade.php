{{-- The result of a withdraw / deposit / transfer. $res comes from BankClient::send(). --}}
@php($d = $res['data'])
@php($approved = $res['ok'] && ($d['status'] ?? '') === 'approved')
@extends('atm.frame', [
    'cash' => $approved && $type === 'withdraw',
    'keys' => [
        'l2' => ['label' => 'Finish', 'post' => route('logout')],
        'r2' => ['label' => 'Another service', 'href' => route('menu')],
    ],
])
@section('title', $approved ? 'Done' : 'Not completed')
@section('screen')
    @if($approved)
        <h1>{{ $type === 'withdraw' ? 'Please take your cash' : 'Done' }}</h1>
        <p>{{ $d['message'] }}</p>
    @else
        <h1>Not completed</h1>
        <p class="prompt">{{ $d['message'] ?? 'The bank refused this request.' }}</p>
    @endif
    @isset($d['balance'])
        <p style="margin:0">Balance now</p>
        <div class="big">UGX {{ number_format($d['balance']) }}</div>
    @endisset
    @if(!empty($d['replayed']))
        <p class="prompt" style="font-size:14px">The bank had already done this request, so it sent back the saved answer and charged nothing extra.</p>
    @endif
@endsection
@section('receipt')
    @include('atm.receipt', [
        'lines' => array_values(array_filter([
            ['Service', ucfirst($type)],
            ['Amount', 'UGX '.number_format($amount)],
            isset($d['balance']) ? ['Balance', 'UGX '.number_format($d['balance'])] : null,
            ['Result', $approved ? 'Approved' : ($d['message'] ?? 'Not completed')],
            ['Request ID', $request_id],
            $res['server'] ? ['Answered by', 'Server '.($d['server'] ?? '?').', epoch '.($d['epoch'] ?? '?')] : null,
            !empty($d['replayed']) ? ['Note', 'Repeat recognised, charged once'] : null,
        ])),
        'attempts' => $res['attempts'],
    ])
@endsection