<?php

namespace App\Console\Commands;

use App\Models\NodeEvent;
use Illuminate\Console\Command;

/**
 * DEMO: make ONE bank server behave as if it crashed, without closing any window:
 *     php artisan cashsync:crash --env=bank_a
 * Its API answers only errors and its heartbeat goes silent, so the other server takes over.
 * Undo with:  php artisan cashsync:recover --env=bank_a
 * (It works by creating a small "flag" file, e.g. storage/app/crashed_A)
 */
class Crash extends Command
{
    protected $signature = 'cashsync:crash';

    protected $description = 'Simulate a crash of this bank server (undo with cashsync:recover)';

    /** Where the flag file for THIS server lives. */
    public static function flagFile(): string
    {
        return storage_path('app/crashed_'.config('cashsync.node_name'));
    }

    /** Is this server currently "crashed"? */
    public static function isCrashed(): bool
    {
        return file_exists(static::flagFile());
    }

    public function handle(): int
    {
        touch(static::flagFile());   // create the flag file
        NodeEvent::log('SIMULATED CRASH: this server stopped answering.', 'danger');
        $this->warn('Server '.config('cashsync.node_name').' is now "crashed". Watch the other server take over in about 3 seconds.');

        return self::SUCCESS;
    }
}