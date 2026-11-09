{{-- Bank server operations screen. Reloads itself every 2 seconds. --}}
@php($primary = $state->isPrimary())
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="2">
    <title>Server {{ config('cashsync.node_name') }} ({{ $state->role }})</title>
        <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="alternate icon" href="/favicon.ico">
    <style>
        :root {
            --ink: #1D2329; --muted: #5C656E; --rule: #CDD2D7; --paper: #FFFFFF; --page: #EEF0F2;
            --primary: #1E6B45;   /* green bar: this server is in charge */
            --backup: #4F5B66;    /* slate bar: standing by */
            --alert: #B3261E; --warn: #8A5A00;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--page); color: var(--ink);
               font: 14px/1.45 "Segoe UI", Tahoma, Arial, sans-serif; }
        .bar { color: #fff; padding: 14px 24px; display: flex; flex-wrap: wrap; gap: 6px 24px;
               align-items: baseline; justify-content: space-between; }
        .bar.primary { background: var(--primary); } .bar.backup { background: var(--backup); }
               .bar h1 { margin: 0; font-size: 20px; font-weight: 600; display: flex; align-items: center; gap: 10px; }
        .bar h1 img { width: 28px; height: 28px; border-radius: 7px; box-shadow: 0 0 0 2px rgba(255,255,255,.35); }
        .bar small { font-size: 13px; opacity: .85; }
        main { max-width: 1180px; margin: 0 auto; padding: 20px 24px 40px; }

        .facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); background: var(--paper);
                 border: 1px solid var(--rule); }
        .facts div { padding: 12px 16px; border-right: 1px solid var(--rule); }
        .facts div:last-child { border-right: 0; }
        .facts dt { color: var(--muted); font-size: 13px; }
        .facts dd { margin: 2px 0 0; font-size: 17px; font-weight: 600; }
        .facts dd small { display: block; font-size: 13px; font-weight: 400; color: var(--muted); }
        .up { color: var(--primary); } .down { color: var(--alert); } .warn { color: var(--warn); }

        .cols { display: grid; grid-template-columns: minmax(0, 5fr) minmax(0, 6fr); gap: 20px; margin-top: 20px; }
        section { background: var(--paper); border: 1px solid var(--rule); }
        section h2 { font-size: 15px; margin: 0; padding: 10px 14px; border-bottom: 1px solid var(--rule); }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-weight: 600; color: var(--muted); font-size: 13px; padding: 6px 14px;
             border-bottom: 1px solid var(--rule); background: #F7F8F9; }
        td { padding: 6px 14px; border-bottom: 1px solid #E4E7EA; vertical-align: top; }
        tr:last-child td { border-bottom: 0; }
        .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .id { color: var(--muted); font-size: 12px; }
        .log td:first-child { color: var(--muted); white-space: nowrap; width: 1%; }
        .log .success { color: var(--primary); } .log .danger { color: var(--alert); } .log .warning { color: var(--warn); }
        .rejected { color: var(--alert); }
        .note { background: #FFF6DB; border: 1px solid #E8D28A; padding: 10px 14px; margin-top: 16px; }
        a { color: inherit; }
        .scroll { overflow-x: auto; }
        @media (max-width: 860px) { .cols { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<header class="bar {{ $primary ? 'primary' : 'backup' }}">
        <h1><img src="/favicon.svg" alt="CashSync">Server {{ config('cashsync.node_name') }} is the {{ $state->role }}</h1>
    <small>Epoch {{ $state->epoch }}, database {{ config('database.connections.mysql.database') }}, updated {{ now()->format('H:i:s') }}</small>
</header>

<main>
    <dl class="facts">
        <div>
            <dt>This server</dt>
            <dd>{{ ucfirst($state->role) }}, epoch {{ $state->epoch }}
                <small>
                    @if($primary && $state->degraded)<span class="warn">Working without a backup: copies are not being confirmed</span>
                    @elseif($primary)Every transaction is copied to the backup
                    @else Standing by with a full copy of the data @endif
                </small>
            </dd>
        </div>
        <div>
            <dt>Other server (<a href="{{ config('cashsync.peer_url') }}/dashboard">{{ config('cashsync.peer_url') }}</a>)</dt>
            <dd class="{{ $peerAlive ? 'up' : 'down' }}">{{ $peerAlive ? 'Reachable' : 'Not answering' }}
                <small>
                    @if($state->peer_role){{ ucfirst($state->peer_role) }}, epoch {{ $state->peer_epoch }}, @endif
                    last heard {{ $state->peer_seen_at?->diffForHumans() ?? 'never' }}
                </small>
            </dd>
        </div>
        <div>
            <dt>Money held in all accounts</dt>
            <dd>UGX {{ number_format($total) }}
                <small>{{ $count }} transactions recorded</small>
            </dd>
        </div>
    </dl>

    @if(!$state->peer_seen_at)
        <p class="note">No heartbeat yet. Start this server's heartbeat in its own window:
            php artisan cashsync:heartbeat --env={{ app()->environment() }}</p>
    @endif
    @if(config('cashsync.disable_locks'))
        <p class="note"><b>Account locks are switched off</b> (race-condition demo). Set CASHSYNC_DISABLE_LOCKS=false when you are done.</p>
    @endif

    <div class="cols">
        <section>
            <h2>Accounts</h2>
            <table>
                <tr><th>Account</th><th>Holder</th><th class="num">Balance (UGX)</th><th class="num">Version</th></tr>
                @foreach($accounts as $a)
                    <tr>
                        <td>{{ $a->account_no }}</td>
                        <td>{{ $a->holder_name }}</td>
                        <td class="num {{ $a->balance < 0 ? 'down' : '' }}">{{ number_format($a->balance) }}</td>
                        <td class="num">{{ $a->version }}</td>
                    </tr>
                @endforeach
            </table>
        </section>

        <section>
            <h2>Event log</h2>
            <table class="log">
                @forelse($events as $e)
                    <tr><td>{{ $e->created_at->format('H:i:s') }}</td><td class="{{ $e->level }}">{{ $e->message }}</td></tr>
                @empty
                    <tr><td></td><td>Nothing has happened yet.</td></tr>
                @endforelse
            </table>
        </section>
    </div>

    <section style="margin-top:20px">
        <h2>Latest transactions</h2>
        <div class="scroll">
            <table>
                <tr><th>Time</th><th>Type</th><th>Account</th><th class="num">Amount</th><th class="num">Balance after</th>
                    <th>Result</th><th>Done by</th><th class="num">Epoch</th><th>Request ID</th></tr>
                @forelse($transactions as $t)
                    <tr>
                        <td>{{ $t->created_at->format('H:i:s') }}</td>
                        <td>{{ ucfirst($t->type) }}</td>
                        <td>{{ $t->account_no }}@if($t->to_account_no) to {{ $t->to_account_no }}@endif</td>
                        <td class="num">{{ number_format($t->amount) }}</td>
                        <td class="num">{{ $t->balance_after !== null ? number_format($t->balance_after) : '' }}</td>
                        <td class="{{ $t->status }}">{{ $t->status === 'approved' ? 'Approved' : $t->message }}</td>
                        <td>Server {{ $t->processed_by }}</td>
                        <td class="num">{{ $t->epoch }}</td>
                        <td class="id" title="Transaction {{ $t->id }}">{{ $t->request_id }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9">No transactions yet. Use the ATM at http://127.0.0.1:8000</td></tr>
                @endforelse
            </table>
        </div>
    </section>
</main>
</body>
</html>