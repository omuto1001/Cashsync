{{--
    The ATM machine. Every ATM page fills in:
      @section('screen')   what shows on the blue screen
      $keys                the side buttons: l1, l2 (left) and r1, r2 (right). Each one is
                           ['label' => 'Text', 'href' => url]           a link
                           ['label' => 'Text', 'submit' => 'atm-form']   submits the screen's form
                           ['label' => 'Text', 'post' => url]            a small POST form
      $cash                true = notes come out of the cash slot
      @section('receipt')  optional printed receipt under the machine
--}}
@php($keys = $keys ?? [])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'CashSync ATM')</title>
        <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="alternate icon" href="/favicon.ico">
    <style>
        :root {
            --wall: #D6D9DC;      /* the wall the machine is set into */
            --steel: #8A9096;     /* machine casing */
            --steel-dark: #5E646A;
            --bezel: #23272B;     /* black frame around the screen */
            --screen: #12407D;    /* classic ATM blue */
            --screen-ink: #FFFFFF;
            --highlight: #FFD447; /* ATM yellow for prompts and amounts */
            --key: #D3D6D9;       /* side buttons */
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--wall); color: #1C2024;
               font-family: Tahoma, Verdana, "Segoe UI", sans-serif; }
        .wrap { max-width: 600px; margin: 0 auto; padding: 28px 16px 48px; }

        /* ---------- the machine ---------- */
        .machine { background: var(--steel); border-radius: 14px 14px 4px 4px; padding: 0 0 20px;
                   box-shadow: inset 0 -6px 0 var(--steel-dark); }
        .sign { background: #F4F5F6; border-radius: 14px 14px 0 0; padding: 14px 22px; display: flex;
                justify-content: space-between; align-items: baseline; border-bottom: 4px solid var(--screen); }
                .sign b { color: var(--screen); font-size: 22px; letter-spacing: -0.3px;
                  display: inline-flex; align-items: center; gap: 9px; }
        .sign b img { width: 30px; height: 30px; }
        .sign span { color: #4A5056; font-size: 13px; }

        .face { display: grid; grid-template-columns: 52px minmax(0, 1fr) 52px;
                grid-template-rows: minmax(250px, auto) 56px 56px; column-gap: 12px; padding: 22px 16px 0; }
        .screen-bg { grid-column: 2; grid-row: 1 / 4; background: var(--screen);
                     border: 12px solid var(--bezel); border-radius: 6px; }
        .screen { grid-column: 2; grid-row: 1; padding: 30px 32px 10px; color: var(--screen-ink); z-index: 1; }
        .screen h1 { font-size: 24px; margin: 0 0 6px; font-weight: bold; }
        .screen p { margin: 0 0 14px; font-size: 16px; line-height: 1.4; }
        .screen .prompt { color: var(--highlight); }
        .screen .big { font-size: 34px; font-weight: bold; color: var(--highlight); margin: 4px 0 12px; }
        .screen label { display: block; font-size: 14px; margin: 10px 0 4px; }
        .screen input[type=text], .screen input[type=password], .screen input[type=number], .screen select {
            width: 100%; font: inherit; font-size: 18px; padding: 8px 10px; border: 0; border-radius: 2px;
            background: #FFFFFF; color: #10223A; }
        .screen .error { color: var(--highlight); font-weight: bold; margin: 0 0 10px; }
        .screen table { width: 100%; border-collapse: collapse; font-size: 15px; }
        .screen td { padding: 4px 0; border-bottom: 1px solid rgba(255,255,255,.18); }
        .screen td.amt { text-align: right; white-space: nowrap; }
        .quick { display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0 4px; }
        .quick button { font: inherit; font-size: 14px; background: transparent; color: var(--highlight);
                        border: 1px solid var(--highlight); padding: 5px 10px; cursor: pointer; }
        .demo { display: flex; gap: 8px; align-items: flex-start; font-size: 13px; margin-top: 12px; opacity: .85; }
        .demo input { margin-top: 2px; }

        /* soft-key label rows inside the screen, lined up with the side buttons */
        .softrow { grid-column: 2; display: flex; justify-content: space-between; align-items: center;
                   padding: 0 24px; z-index: 1; font-size: 16px; }
        .softrow.r2 { grid-row: 2; } .softrow.r3 { grid-row: 3; }
        .softrow a, .softrow button, .softrow form { color: var(--screen-ink); text-decoration: none; background: none;
                   border: 0; font: inherit; cursor: pointer; padding: 0; margin: 0; }
        .softrow .left::before { content: "◂ "; color: var(--highlight); }
        .softrow .right::after { content: " ▸"; color: var(--highlight); }
        .softrow a:hover, .softrow button:hover { color: var(--highlight); }

        /* physical side buttons */
        .key { display: block; width: 100%; height: 40px; align-self: center; background: var(--key);
               border: 0; border-bottom: 4px solid #9A9FA4; border-radius: 3px; cursor: pointer; padding: 0; }
        .key:active { border-bottom-width: 1px; transform: translateY(3px); }
        .key[disabled] { opacity: .55; cursor: default; }
        .k-l1 { grid-column: 1; grid-row: 2; } .k-l2 { grid-column: 1; grid-row: 3; }
        .k-r1 { grid-column: 3; grid-row: 2; } .k-r2 { grid-column: 3; grid-row: 3; }
        .key-form { display: contents; }

        /* card slot and cash dispenser */
        .slots { display: grid; grid-template-columns: 180px 1fr; gap: 20px; padding: 22px 32px 0; align-items: end; }
        .slot-label { font-size: 12px; color: #EEF0F2; margin-bottom: 5px; }
        .card-slot { height: 14px; background: var(--bezel); border-radius: 7px; }
        .slot-label form { display: inline; }
        .eject { font: inherit; font-size: 12px; white-space: nowrap; margin-left: 8px; background: #F4F5F6; color: #1C2024;
                 border: 0; border-bottom: 2px solid #9A9FA4; border-radius: 3px; padding: 2px 8px; cursor: pointer; }
        .cash-slot { height: 22px; background: var(--bezel); border-radius: 3px; position: relative; overflow: visible; }
        .notes { position: absolute; left: 50%; bottom: 8px; transform: translateX(-50%); width: 64%; }
        .notes i { display: block; height: 30px; margin-top: -24px; background: #7FA77A; border: 2px solid #5D8459;
                   border-radius: 2px; animation: dispense 1.1s ease-out both; }
        .notes i:nth-child(2) { background: #8CB287; animation-delay: .12s; }
        .notes i:nth-child(3) { background: #9BBE96; animation-delay: .24s; }
        @keyframes dispense { from { transform: translateY(26px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        @media (prefers-reduced-motion: reduce) { .notes i { animation: none; } }

        /* the printed receipt */
        .receipt { width: 78%; margin: 0 auto; background: #FFFFFF; padding: 18px 20px 22px;
                   font-family: "Courier New", Courier, monospace; font-size: 13px; line-height: 1.45; color: #22262A;
                   box-shadow: 0 1px 0 #B9BDC1; border-top: 3px dashed #BFC3C7; }
        .receipt h2 { font-size: 14px; text-align: center; margin: 0 0 10px; }
        .receipt .row { display: flex; justify-content: space-between; gap: 12px; }
        .receipt .row span:last-child { text-align: right; word-break: break-all; }
        .receipt hr { border: 0; border-top: 1px dashed #9EA3A8; margin: 10px 0; }
        .receipt ol { margin: 4px 0 0; padding-left: 18px; }
        .receipt .bad { color: #A12A1F; } .receipt .ok { color: #1E6B45; }
        .servers { font-size: 12px; color: #4A5056; text-align: center; margin-top: 18px; }

        :focus-visible { outline: 3px solid var(--highlight); outline-offset: 2px; }
        @media (max-width: 480px) {
            .face { grid-template-columns: 40px minmax(0, 1fr) 40px; column-gap: 8px; padding: 16px 8px 0; }
            .screen { padding: 22px 18px 8px; } .softrow { padding: 0 14px; font-size: 14px; }
            .slots { grid-template-columns: 150px 1fr; padding: 22px 16px 0; } .receipt { width: 94%; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="machine">
        <div class="sign">
                        <b><img src="/favicon.svg" alt="">CashSync</b>
            <span>{{ session('atm_id', 'ATM1') === 'ATM2' ? 'ATM2, Mbarara branch' : 'ATM1, Kampala Road' }}</span>
        </div>

        <div class="face">
            <div class="screen-bg"></div>
            <div class="screen">
                @if(session('info'))<p class="prompt">{{ session('info') }}</p>@endif
                @if(session('error'))<p class="error">{{ session('error') }}</p>@endif
                @if($errors->any())<p class="error">{{ $errors->first() }}</p>@endif
                @yield('screen')
            </div>

            {{-- The two rows of soft-key labels, and the four physical buttons beside them --}}
            @foreach([['l1', 'r1', 'r2'], ['l2', 'r2', 'r3']] as [$l, $r, $row])
                <div class="softrow {{ $row }}">
                    <span>@isset($keys[$l])@include('atm.key', ['k' => $keys[$l], 'side' => 'left'])@endisset</span>
                    <span>@isset($keys[$r])@include('atm.key', ['k' => $keys[$r], 'side' => 'right'])@endisset</span>
                </div>
            @endforeach
            @foreach(['l1', 'l2', 'r1', 'r2'] as $slot)
                @include('atm.key', ['k' => $keys[$slot] ?? null, 'side' => 'button', 'slot' => $slot])
            @endforeach
        </div>

        <div class="slots">
            <div>
                <div class="slot-label">Card
                    @if(session('account_no'))
                        <form method="post" action="{{ route('logout') }}">@csrf<button class="eject">Return card</button></form>
                    @endif
                </div>
                <div class="card-slot"></div>
            </div>
            <div>
                <div class="slot-label">Cash</div>
                <div class="cash-slot">
                    @if(!empty($cash))<div class="notes" aria-label="Cash dispensed"><i></i><i></i><i></i></div>@endif
                </div>
            </div>
        </div>
    </div>

    @yield('receipt')

    <p class="servers">Bank servers: {{ implode(', then ', config('cashsync.bank_servers')) }}</p>
</div>
</body>
</html>