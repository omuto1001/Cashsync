{{-- "Behind the scenes": lists every try the ATM made (timeouts, retries, server switches). --}}
@if(!empty($attempts))
<div class="card" style="margin-top:16px;background:var(--tint)">
    <h3>Behind the scenes: what the ATM did</h3>
    <ol style="margin:0;padding-left:20px;font-size:14px">
        @foreach($attempts as $a)
            <li style="margin-bottom:4px"><strong>{{ $a['server'] }}</strong>:
                <span style="color:{{ $a['ok'] ? 'var(--green)' : 'var(--red)' }}">{{ $a['result'] }}</span></li>
        @endforeach
    </ol>
</div>
@endif