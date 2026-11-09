{{-- The shared page layout: every ATM and dashboard page is placed inside this. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- The dashboard passes $refresh to reload itself every few seconds --}}
    @isset($refresh)<meta http-equiv="refresh" content="{{ $refresh }}">@endisset
    <title>@yield('title', 'CashSync')</title>
    <style>
        :root { --ink:#1E1B2A; --plum:#2A1840; --violet:#6C3BAA; --gold:#C28A12; --grey:#6E6A78;
                --tint:#F2EEF7; --line:#E1D8EA; --green:#2E8B57; --red:#C0392B; --blue:#2F6690; }
        * { box-sizing: border-box; }
        body { margin:0; font-family: Calibri, "Segoe UI", Arial, sans-serif; color:var(--ink); background:#F7F5FA; }
        header.top { background:var(--plum); color:#fff; padding:14px 24px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
        header.top .brand { font-family: Arial, sans-serif; font-weight:700; font-size:22px; color:var(--gold); }
        header.top .sub { color:#E9E3F2; font-size:14px; }
        main { max-width: 1100px; margin: 24px auto; padding: 0 16px; }
        .card { background:#fff; border:1px solid var(--line); border-radius:12px; padding:20px; box-shadow:0 2px 6px rgba(42,24,64,.06); }
        h1,h2,h3 { font-family: Arial, sans-serif; color:var(--plum); margin:0 0 12px; }
        h1 { font-size:24px; } h2 { font-size:18px; } h3 { font-size:15px; }
        .muted { color:var(--grey); }
        .btn { display:inline-block; border:0; border-radius:10px; padding:12px 18px; font-size:16px; font-weight:700; cursor:pointer;
               background:var(--violet); color:#fff; text-decoration:none; text-align:center; }
        .btn.gold { background:var(--gold); } .btn.light { background:var(--tint); color:var(--plum); } .btn.red { background:var(--red); }
        .btn:hover { filter:brightness(1.08); }
        input, select { width:100%; padding:12px; font-size:16px; border:1px solid var(--line); border-radius:8px; margin:4px 0 14px; }
        label { font-weight:700; font-size:14px; color:var(--plum); }
        .alert { padding:12px 14px; border-radius:10px; margin-bottom:14px; }
        .alert.ok { background:#E5F4E4; color:#1F5F3B; } .alert.bad { background:#FBEBEB; color:#8C2A20; } .alert.info { background:var(--tint); color:var(--plum); }
        table { width:100%; border-collapse:collapse; font-size:14px; }
        th { background:var(--plum); color:#fff; text-align:left; padding:8px; font-weight:600; }
        td { padding:7px 8px; border-bottom:1px solid var(--line); vertical-align:top; }
        tr:nth-child(even) td { background:#FAF8FC; }
        .pill { display:inline-block; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:700; }
        .pill.green { background:#E5F4E4; color:#1F5F3B; } .pill.red { background:#FBEBEB; color:#8C2A20; }
        .pill.gold { background:#F9EEDA; color:#7A5408; } .pill.violet { background:#EFEDFF; color:var(--violet); } .pill.grey { background:#EEE; color:#555; }
        .money { font-variant-numeric: tabular-nums; white-space:nowrap; }
        .grid { display:grid; gap:16px; }
        @media (min-width: 800px) { .grid.two { grid-template-columns: 1fr 1fr; } .grid.three { grid-template-columns: repeat(3, 1fr); } }
        code { background:var(--tint); padding:2px 6px; border-radius:6px; font-size:13px; }
    </style>
</head>
<body>
@yield('body')
</body>
</html>