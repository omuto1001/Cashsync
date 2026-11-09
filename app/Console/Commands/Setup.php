<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * One-step (re)set-up of a bank server: creates its MySQL database if needed, builds the
 * tables and adds the demo accounts.
 *     php artisan cashsync:setup --env=bank_a
 *     php artisan cashsync:setup --env=bank_b
 * WARNING: this ERASES that server's data. Add --force to skip the "are you sure?" question.
 */
class Setup extends Command
{
    protected $signature = 'cashsync:setup {--force : Do not ask before erasing the database}';

    protected $description = 'Create (or reset) this bank server\'s database with the demo accounts';

    public function handle(): int
    {
        if (config('cashsync.mode') !== 'bank') {
            $this->info('This is the ATM app: it has no database, nothing to set up.');

            return self::SUCCESS;
        }

        $db = config('database.connections.mysql');   // host, port, user, password from the settings file
        $name = $db['database'];                       // cashsync_a or cashsync_b

        if (! $this->option('force') && ! $this->confirm("This will ERASE and recreate database '{$name}' for server ".config('cashsync.node_name').'. Continue?', true)) {
            return self::FAILURE;
        }

        // Connect to MySQL WITHOUT choosing a database, and create ours if it does not exist
        $pdo = new PDO("mysql:host={$db['host']};port={$db['port']}", $db['username'], $db['password']);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        DB::purge('mysql');   // forget the old connection so Laravel reconnects to the new database

        // Drop all tables, build them again (migration) and add the demo accounts (seeder)
        Artisan::call('migrate:fresh', ['--force' => true, '--seed' => true], $this->output);

        // Clear a leftover "simulated crash" flag from a previous demo
        @unlink(Crash::flagFile());

        $this->info('Server '.config('cashsync.node_name').' is ready as '.config('cashsync.start_role').", database '{$name}'.");

        return self::SUCCESS;
    }
}