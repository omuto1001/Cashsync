<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * RACE TEST (assignment task c): many ATMs withdraw from the SAME account at the SAME moment.
 * With the locks on, the bank never pays out more than the account holds.
 *
 *     php artisan cashsync:race --env=atm
 *     php artisan cashsync:race --env=atm --clients=20 --amount=100000 --start=150000
 */
class RaceTest extends Command
{
    protected $signature = 'cashsync:race
        {--clients=10 : How many withdrawals to fire at the same moment}
        {--amount=100000 : Amount of each withdrawal (UGX)}
        {--start=150000 : Balance to put in the account before the test (UGX)}
        {--account=9999 : Test account to use}
        {--pin=0000 : PIN of the test account}';

    protected $description = 'Fire many simultaneous withdrawals at one account and check no money is lost';

    public function handle(): int
    {
        // Find whichever server is the primary right now
        $primary = $this->findPrimary();
        if (! $primary) {
            $this->error('No primary bank server is answering.');

            return self::FAILURE;
        }
        $acct = $this->option('account');
        $n = (int) $this->option('clients');
        $amount = (int) $this->option('amount');
        $start = (int) $this->option('start');

        // 1. Put a known balance in the test account. This goes through the bank like any
        //    other request, so the backup gets the same balance too.
        Http::withHeaders(['X-Admin-Key' => config('cashsync.admin_key')])->acceptJson()
            ->post($primary.'/api/adjust', ['request_id' => (string) Str::uuid(), 'account_no' => $acct, 'new_balance' => $start])
            ->throw();

        $this->info("Account {$acct} set to UGX ".number_format($start).". Firing {$n} withdrawals of UGX ".number_format($amount)." at the same moment on {$primary} ...");

        // 2. Http::pool sends ALL the withdrawals at once (like N ATMs pressing "Confirm" together).
        //    Each one has its own UUID, so they are N different customers' requests.
        $t0 = microtime(true);
        $responses = Http::pool(function (Pool $pool) use ($n, $primary, $acct, $amount) {
            for ($i = 1; $i <= $n; $i++) {
                $pool->as("w{$i}")->timeout(60)->acceptJson()->post($primary.'/api/withdraw', [
                    'request_id' => (string) Str::uuid(),
                    'atm_id' => 'RACE',
                    'account_no' => $acct,
                    'pin' => $this->option('pin'),
                    'amount' => $amount,
                ]);
            }
        });
        $secs = microtime(true) - $t0;

        // 3. Count how many were approved, and show each answer
        $approved = 0;
        $rows = [];
        foreach ($responses as $key => $r) {
            if ($r instanceof \Throwable) {
                $rows[] = [$key, 'ERROR', $r->getMessage(), ''];

                continue;
            }
            $d = $r->json();
            $approved += ($d['status'] ?? '') === 'approved' ? 1 : 0;
            $rows[] = [$key, $d['status'] ?? '?', $d['message'] ?? '', isset($d['balance']) ? number_format($d['balance']) : ''];
        }
        $this->table(['Request', 'Status', 'Message', 'Balance after'], $rows);

        // 4. Read the final balance and compare with what SHOULD be left
        $final = Http::acceptJson()->post($primary.'/api/balance', ['account_no' => $acct, 'pin' => $this->option('pin')])->json('balance');
        $paid = $approved * $amount;
        $expected = $start - $paid;
        $correct = $final === $expected && $final >= 0;

        $this->newLine();
        $this->table(['Measure', 'Value'], [
            ['Starting balance', 'UGX '.number_format($start)],
            ['Withdrawals approved', "{$approved} of {$n}"],
            ['Cash paid out', 'UGX '.number_format($paid)],
            ['Final balance', 'UGX '.number_format($final)],
            ['Expected (start - paid out)', 'UGX '.number_format($expected)],
            ['Time taken', round($secs, 2).' s'],
        ]);
        $correct
            ? $this->info('CORRECT: the bank never paid out more than the account held.')
            : $this->error('BUG: the bank paid out more than the account held (race condition). Are the locks switched off?');

        return $correct ? self::SUCCESS : self::FAILURE;
    }

    /** Ask each bank server for its health report and return the one that says "primary". */
    private function findPrimary(): ?string
    {
        foreach (config('cashsync.bank_servers') as $s) {
            try {
                if (Http::timeout(2)->get($s.'/api/health')->json('role') === 'primary') {
                    return $s;
                }
            } catch (\Throwable) {
                // that server is down: try the next one
            }
        }

        return null;
    }
}