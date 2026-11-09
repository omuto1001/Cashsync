<?php

namespace App\Console\Commands;

use App\Services\BankClient;
use Illuminate\Console\Command;

/**
 * RECOVERY-TIME PROBE (assignment task d): measures how long customers cannot be served
 * when the primary crashes.
 * Start it, then crash the primary (close its windows, or use cashsync:crash). It keeps
 * asking for a balance 4 times a second, through the ATM's own retry/failover logic.
 *
 *     php artisan cashsync:probe --env=atm --seconds=40
 */
class Probe extends Command
{
    protected $signature = 'cashsync:probe {--seconds=40 : How long to run} {--account=1001} {--pin=1234}';

    protected $description = 'Keep checking a balance and measure how long the bank is unavailable during a failover';

    public function handle(BankClient $client): int
    {
        config(['cashsync.atm_rounds' => 1]);   // report each failure quickly instead of waiting
        $end = microtime(true) + (int) $this->option('seconds');
        $lastOkAt = null;       // when the bank last answered
        $outageStart = null;    // when the current outage began (null = no outage)
        $outages = [];          // the length of each outage, in seconds
        $lastServer = null;

        $this->info('Probing the bank. Crash the primary now to see the failover. Press Ctrl+C to stop early.');

        while (microtime(true) < $end) {
            $now = microtime(true);
            $res = $client->send('/api/balance', ['account_no' => $this->option('account'), 'pin' => $this->option('pin')]);
            $time = date('H:i:s');

            if ($res['ok'] && ($res['data']['status'] ?? '') === 'approved') {
                if ($outageStart !== null) {
                    // The bank answers again: the outage is over. Recovery time = since the last good answer.
                    $outages[] = $lastOkAt ? microtime(true) - $lastOkAt : microtime(true) - $outageStart;
                    $this->info(sprintf('[%s] RECOVERED: served again by %s after %.1f s', $time, $res['server'], end($outages)));
                    $outageStart = null;
                } elseif ($res['server'] !== $lastServer) {
                    $this->line("[{$time}] OK, served by {$res['server']} (epoch {$res['data']['epoch']})");
                }
                $lastServer = $res['server'];
                $lastOkAt = microtime(true);
            } else {
                if ($outageStart === null) {
                    $outageStart = $now;
                    $this->warn("[{$time}] FAILED: bank not reachable, the ATM keeps trying ...");
                }
            }
            usleep(250 * 1000);   // 4 checks per second
        }

               $this->newLine();
        // An outage that was still going when the probe ended is reported too
        if ($outageStart !== null) {
            $outages[] = microtime(true) - ($lastOkAt ?? $outageStart);
            $this->error(sprintf('The bank was STILL DOWN when the probe ended (%.1f s so far). Is the backup\'s heartbeat running?', end($outages)));
        }
        if ($outages) {
            $this->table(['Outage', 'Recovery time'], collect($outages)->map(fn ($s, $i) => ['#'.($i + 1), sprintf('%.1f s', $s)])->all());
        } else {
            $this->info('No outage seen during the probe.');
        }

        return self::SUCCESS;
    }
}