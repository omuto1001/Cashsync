<?php

/*
|--------------------------------------------------------------------------
| CashSync settings
|--------------------------------------------------------------------------
| ONE Laravel project plays THREE roles:
|   - Bank server A  (settings file .env.bank_a)
|   - Bank server B  (settings file .env.bank_b)
|   - The ATM app    (settings file .env.atm)
| We choose the role when starting it, e.g.  php artisan serve --env=bank_a
| Every value below is read from that settings file with env('NAME', default).
*/

return [

    // "bank" = this copy is a bank server, "atm" = this copy is the ATM app
    'mode' => env('CASHSYNC_MODE', 'bank'),

    // ================= BANK SERVER SETTINGS =================

    // This server's name, shown on its dashboard: "A" or "B"
    'node_name' => env('CASHSYNC_NODE', 'A'),

    // The role this server takes the FIRST time it is set up: "primary" or "backup".
    // After that, the role can change by itself during failover.
    'start_role' => env('CASHSYNC_START_ROLE', 'primary'),

    // Web address of the OTHER bank server (A points to B, B points to A).
    // rtrim removes a trailing "/" so we can safely add "/api/..." later.
    'peer_url' => rtrim(env('CASHSYNC_PEER_URL', 'http://127.0.0.1:8002'), '/'),

    // This server's own web address (used for links on the dashboard)
    'self_url' => rtrim(env('CASHSYNC_SELF_URL', 'http://127.0.0.1:8001'), '/'),

    // Heartbeat: ask the other server "are you alive?" every N seconds...
    'heartbeat_interval' => (float) env('CASHSYNC_HEARTBEAT_SECONDS', 1),
    // ...and if the primary misses this many in a row, the backup takes over.
    'heartbeat_misses' => (int) env('CASHSYNC_HEARTBEAT_MISSES', 3),

    // How many seconds the primary waits for the backup to confirm ("ack") a copy
    'replicate_timeout' => (float) env('CASHSYNC_REPLICATE_TIMEOUT', 1.5),

    // The most a customer may withdraw in one day, across ALL ATMs (UGX)
    'daily_limit' => (int) env('CASHSYNC_DAILY_LIMIT', 5000000),

    // DEMO ONLY: true switches OFF the account locks, to show the race-condition bug
    'disable_locks' => (bool) env('CASHSYNC_DISABLE_LOCKS', false),

    // DEMO ONLY: when locks are off, pause this many milliseconds between
    // "check the balance" and "change the balance" so the bug is easy to see
    'unsafe_delay_ms' => (int) env('CASHSYNC_UNSAFE_DELAY_MS', 300),

    // Secret password for the admin "set balance" call used by the test tools
    'admin_key' => env('CASHSYNC_ADMIN_KEY', 'cashsync-demo-key'),

    // ================= ATM SETTINGS =================

    // The bank servers the ATM may use, in order of preference.
    // The settings file gives them as one comma-separated line; here we split
    // it into a list and tidy each address.
    'bank_servers' => array_values(array_filter(array_map(
        fn ($url) => rtrim(trim($url), '/'),
        explode(',', env('CASHSYNC_BANK_SERVERS', 'http://127.0.0.1:8001,http://127.0.0.1:8002'))
    ))),

    // How many seconds the ATM waits for a reply before it gives up on that try
    'atm_timeout' => (float) env('CASHSYNC_ATM_TIMEOUT', 2),

    // How many rounds over the server list the ATM makes before telling the
    // customer "try again later" (a backup needs about 3 seconds to take over)
    'atm_rounds' => (int) env('CASHSYNC_ATM_ROUNDS', 4),
];