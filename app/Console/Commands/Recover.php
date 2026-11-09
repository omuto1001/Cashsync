<?php

namespace App\Console\Commands;

use App\Models\NodeEvent;
use Illuminate\Console\Command;

/**
 * DEMO: bring a "crashed" server back:
 *     php artisan cashsync:recover --env=bank_a
 * Its heartbeat then sees who is primary: if the other server took over, this one
 * steps down to BACKUP and copies every transaction it missed.
 */
class Recover extends Command
{
    protected $signature = 'cashsync:recover';

    protected $description = 'Bring this bank server back after cashsync:crash';

    public function handle(): int
    {
        @unlink(Crash::flagFile());   // delete the flag file
        NodeEvent::log('Server restarted after the simulated crash.', 'success');
        $this->info('Server '.config('cashsync.node_name').' is back. Its heartbeat will check who is primary and catch up.');

        return self::SUCCESS;
    }
}