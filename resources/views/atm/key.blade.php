{{-- One side button. $side = 'left' / 'right' (the label on the screen) or 'button' (the physical key). --}}
@php($cls = $side === 'button' ? 'key k-'.$slot : $side)
@if(!$k)
    @if($side === 'button')<button class="{{ $cls }}" disabled aria-hidden="true" tabindex="-1"></button>@endif
@elseif(isset($k['href']))
    <a class="{{ $cls }}" href="{{ $k['href'] }}" @if($side === 'button') aria-label="{{ $k['label'] }}" @endif>@if($side !== 'button'){{ $k['label'] }}@endif</a>
@elseif(isset($k['submit']))
    <button class="{{ $cls }}" type="submit" form="{{ $k['submit'] }}" @if($side === 'button') aria-label="{{ $k['label'] }}" @endif>@if($side !== 'button'){{ $k['label'] }}@endif</button>
@else
    <form class="key-form" method="post" action="{{ $k['post'] }}">@csrf
        <button class="{{ $cls }}" @if($side === 'button') aria-label="{{ $k['label'] }}" @endif>@if($side !== 'button'){{ $k['label'] }}@endif</button>
    </form>
@endif