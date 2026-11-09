<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * BankClient: the ATM's connection to the bank servers. This is where the ATM
 * survives slow networks and crashed servers.
 *
 *  - It sends each request to the server that answered last time (at first: A).
 *  - It waits at most 2 seconds for a reply (the TIMEOUT).
 *  - No reply? It RETRIES the SAME request (same request ID, so no double charge)
 *    once, and then tries the OTHER server.
 *  - Reply "not_primary"? It goes to the other server straight away.
 *  - Nobody answers? It waits 1 second and goes round again (a backup needs about
 *    3 seconds to take over), up to 4 rounds.
 * Every try is written into $attempts so the ATM screen can show "behind the scenes".
 */
class BankClient
{
    /** A new request ID: a UUID that no other request will ever have. */
    public static function newRequestId(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Send one request to the bank, with retries and failover.
     *
     * @param  string  $path               e.g. '/api/withdraw'
     * @param  array   $payload            the data to send (always includes the same request_id)
     * @param  bool    $simulateLostReply  DEMO: ask the server to hold back its first reply
     * @return array{ok:bool, data:array, server:?string, attempts:array}
     */
    public function send(string $path, array $payload, bool $simulateLostReply = false): array
    {
        $servers = config('cashsync.bank_servers');   // [A's address, B's address]
        $attempts = [];                                // what happened on each try
        $firstTry = true;

        for ($round = 1; $round <= config('cashsync.atm_rounds'); $round++) {
            foreach ($this->serverOrder($servers) as $server) {
                // Up to 2 tries on each server: the first try, then ONE retry with the same request ID
                for ($try = 1; $try <= 2; $try++) {
                    $label = $this->label($server);
                    $start = microtime(true);
                    try {
                        $request = Http::timeout(config('cashsync.atm_timeout'))   // give up after 2 s
                            ->connectTimeout(1)
                            ->acceptJson();
                        if ($simulateLostReply && $firstTry) {
                            // DEMO ONLY: the server does the work but holds back its reply, as if lost
                            $request = $request->withHeaders(['X-Simulate-Lost-Reply' => '1']);
                        }
                        $firstTry = false;
                        $response = $request->post($server.$path, $payload);
                        $ms = (int) round((microtime(true) - $start) * 1000);
                    } catch (ConnectionException $e) {
                        // TIMEOUT or server not running: note it and retry / move on
                        $ms = (int) round((microtime(true) - $start) * 1000);
                        $attempts[] = ['server' => $label, 'result' => 'No reply after '.$ms.' ms (timeout or server down)', 'ok' => false];

                        continue;   // next try on the same server (or next server after 2 tries)
                    }

                    $data = $response->json() ?? [];

                    // The backup politely refuses: go to the other server
                    if ($response->status() === 503 && ($data['error'] ?? '') === 'not_primary') {
                        $attempts[] = ['server' => $label, 'result' => 'Not the primary ('.$data['role'].'), trying the other server', 'ok' => false];
                        break;
                    }
                    // Any other error (e.g. a crashed server answering 503): go to the other server
                    if (! $response->successful()) {
                        $attempts[] = ['server' => $label, 'result' => 'Error HTTP '.$response->status(), 'ok' => false];
                        break;
                    }

                    // SUCCESS. Remember this server, so the next request goes straight to it.
                    $attempts[] = ['server' => $label, 'result' => 'Answered in '.$ms.' ms'
                        .(! empty($data['replayed']) ? ' (repeat recognised: saved answer returned, nothing charged again)' : ''), 'ok' => true];
                    Cache::forever('cashsync_preferred_server', $server);

                    return ['ok' => true, 'data' => $data, 'server' => $label, 'attempts' => $attempts];
                }
            }
            // Nobody answered in this round. A backup needs about 3 seconds to take over: wait 1 s.
            usleep(1000 * 1000);
        }

        // Still nothing after every round: tell the customer, and take no money
        return [
            'ok' => false,
            'data' => ['status' => 'error', 'message' => 'The bank cannot be reached right now. No money was taken. Please try again.'],
            'server' => null,
            'attempts' => $attempts,
        ];
    }

    /** Try the server that answered last time first, then the others. */
    private function serverOrder(array $servers): array
    {
        $preferred = Cache::get('cashsync_preferred_server');
        if ($preferred && in_array($preferred, $servers, true)) {
            return array_values(array_unique(array_merge([$preferred], $servers)));
        }

        return $servers;
    }

    /** A friendly name for a server address, e.g. "Server B (http://127.0.0.1:8002)". */
    private function label(string $server): string
    {
        $i = array_search($server, config('cashsync.bank_servers'), true);

        return 'Server '.(['A', 'B', 'C', 'D'][$i] ?? '?').' ('.$server.')';
    }
}