<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * LOAD TEST (assignment task e): performance with different numbers of clients.
 *
 *     php artisan cashsync:loadtest --env=atm --clients=10  --requests=200
 *     php artisan cashsync:loadtest --env=atm --clients=100 --requests=1000
 *
 * Measures response time (average, 95th percentile, slowest), throughput and success rate.
 * Each run is also added as a row to storage/app/private/loadtest-results.csv (for the report).
 */
class LoadTest extends Command
{
    protected $signature = 'cashsync:loadtest
        {--clients=10 : Number of ATMs sending at the same time}
        {--requests=200 : Total number of requests}
        {--mix=50 : Percent of requests that are deposits (the rest are balance checks)}';

    protected $description = 'Measure response time, throughput and success rate under load';

    public function handle(): int
    {
        // Find the primary bank server
        $server = collect(config('cashsync.bank_servers'))->first(function ($s) {
            try {
                return Http::timeout(2)->get($s.'/api/health')->json('role') === 'primary';
            } catch (\Throwable) {
                return false;
            }
        });
        if (! $server) {
            $this->error('No primary bank server is answering.');

            return self::FAILURE;
        }

        $clients = max(1, (int) $this->option('clients'));
        $total = max(1, (int) $this->option('requests'));
        $mix = (int) $this->option('mix');
        $accounts = ['1001', '1002', '2002', '3003'];

        $this->info("Load test on {$server}: {$total} requests, {$clients} clients at a time, {$mix}% deposits ...");

        $times = [];     // response time of every successful request, in milliseconds
        $ok = 0;
        $failed = 0;
        $t0 = microtime(true);
        $bar = $this->output->createProgressBar($total);

        // Send the requests in batches: each batch = $clients requests at the same moment
        for ($sent = 0; $sent < $total; $sent += $clients) {
            $batch = min($clients, $total - $sent);
            $responses = Http::pool(function (Pool $pool) use ($batch, $server, $accounts, $mix) {
                for ($i = 0; $i < $batch; $i++) {
                    $acct = $accounts[array_rand($accounts)];
                    $req = $pool->timeout(30)->acceptJson();
                    if (random_int(1, 100) <= $mix) {
                        // a deposit of 1,000 (changes money, so it is locked and replicated)
                        $req->post($server.'/api/deposit', ['request_id' => (string) Str::uuid(), 'atm_id' => 'LOAD',
                            'account_no' => $acct, 'pin' => '1234', 'amount' => 1000]);
                    } else {
                        // a balance check (read only)
                        $req->post($server.'/api/balance', ['account_no' => $acct, 'pin' => '1234']);
                    }
                }
            });
            foreach ($responses as $r) {
                if ($r instanceof \Illuminate\Http\Client\Response && $r->successful() && ($r->json('status') === 'approved')) {
                    $ok++;
                    $times[] = ($r->transferStats?->getTransferTime() ?? 0) * 1000;   // seconds -> ms
                } else {
                    $failed++;
                }
            }
            $bar->advance($batch);
        }
        $bar->finish();
        $this->newLine(2);

        // Work out the measurements
        $wall = microtime(true) - $t0;                                 // total time taken
        sort($times);
        $avg = $times ? array_sum($times) / count($times) : 0;         // average response time
        $p95 = $times ? $times[(int) floor(0.95 * (count($times) - 1))] : 0;   // 95% finish within this
        $max = $times ? end($times) : 0;                               // slowest response
        $throughput = $ok / max($wall, 0.001);                         // successful requests per second
        $success = 100 * $ok / $total;                                 // success rate in %

        $this->table(['Measure', 'Value'], [
            ['Clients at the same time', $clients],
            ['Requests sent', $total],
            ['Successful', "{$ok} ({$this->fmt($success)}%)"],
            ['Failed', $failed],
            ['Average response time', $this->fmt($avg).' ms'],
            ['95th percentile response time', $this->fmt($p95).' ms'],
            ['Slowest response', $this->fmt($max).' ms'],
            ['Total time', $this->fmt($wall).' s'],
            ['Throughput', $this->fmt($throughput).' requests/second'],
        ]);

        // Save one row per run to a CSV file you can open in Excel for the report
        $file = 'loadtest-results.csv';
        if (! Storage::exists($file)) {
            Storage::put($file, 'time,clients,requests,successful,success_rate_pct,avg_ms,p95_ms,max_ms,total_s,throughput_rps');
        }
        Storage::append($file, implode(',', [now()->format('Y-m-d H:i:s'), $clients, $total, $ok,
            $this->fmt($success), $this->fmt($avg), $this->fmt($p95), $this->fmt($max), $this->fmt($wall), $this->fmt($throughput)]));
        $this->info('Saved to '.Storage::path($file));

        return self::SUCCESS;
    }

    /** Format a number with 1 decimal place, e.g. 289.94 -> "289.9" */
    private function fmt(float $v): string
    {
        return number_format($v, 1, '.', '');
    }
}