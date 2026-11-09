{{-- The printed receipt. $lines = [[left, right], ...], $attempts = what the ATM did. --}}
<div class="receipt">
    <h2>CashSync SACCO<br>{{ session('atm_id', 'ATM1') }} {{ now()->format('d/m/Y H:i:s') }}</h2>
    @foreach($lines as [$left, $right])
        <div class="row"><span>{{ $left }}</span><span>{{ $right }}</span></div>
    @endforeach
    @if(!empty($attempts))
        <hr>
        <div>How the ATM reached the bank:</div>
        <ol>
            @foreach($attempts as $a)
                <li><span class="{{ $a['ok'] ? 'ok' : 'bad' }}">{{ $a['server'] }}: {{ $a['result'] }}</span></li>
            @endforeach
        </ol>
    @endif
</div>