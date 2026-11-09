<?php

namespace App\Console\Commands;

use Dotenv\Dotenv;
use Illuminate\Console\Command;

/**
 * Opens the CashSync GUI from the Command Prompt:
 *     php artisan cashsync:gui
 * Starts both bank servers, their heartbeats and both ATMs (each in its own
 * window), then opens the ATMs and the dashboards in the browser.
 */
class Gui extends Command
{
    protected $signature = 'cashsync:gui';

    protected $description = 'Start CashSync and open its screens in the browser';

    public function handle(): int
    {
        $php = PHP_BINARY;          // the same php.exe you typed this command with
        $dir = base_path();         // the project folder

        // 1. Build all the screens (Blade views) once now, so the two ATMs
        //    do not both try to build the same file at the same moment.
        $this->call('view:clear');
        $this->call('view:cache');

        // 2. Forget the settings this window loaded from the default .env file.
        //    Otherwise the new windows copy them and ignore --env=bank_a etc.
        foreach (['.env', '.env.bank_a', '.env.bank_b', '.env.atm'] as $file) {
            $path = base_path($file);
            if (is_file($path)) {
                foreach (array_keys(Dotenv::parse(file_get_contents($path))) as $key) {
                    putenv($key);   // putenv('NAME') with no "=" removes the setting
                }
            }
        }
        putenv('APP_ENV');

        // 3. [window title, command] for each part of the system
        $parts = [
            ['BANK A (8001)', 'artisan serve --port=8001 --env=bank_a'],
            ['BANK B (8002)', 'artisan serve --port=8002 --env=bank_b'],
            ['HEARTBEAT A',   'artisan cashsync:heartbeat --env=bank_a'],
            ['HEARTBEAT B',   'artisan cashsync:heartbeat --env=bank_b'],
            ['ATM 1 (8000)',  'artisan serve --port=8000 --env=atm'],
            ['ATM 2 (8003)',  'artisan serve --port=8003 --env=atm'],
        ];

        foreach ($parts as [$title, $cmd]) {
            $this->info("Starting {$title} ...");
            // "start" opens a new Command Prompt window that keeps running
            pclose(popen("start \"{$title}\" /D \"{$dir}\" cmd /k \"\"{$php}\" {$cmd}\"", 'r'));
            usleep(700000);         // a short pause so the servers start in order
        }

        $this->info('Waiting for the servers to be ready ...');
        sleep(5);

        // 4. Open the GUI screens in the default browser
        foreach (['http://127.0.0.1:8000', 'http://localhost:8003',
                  'http://127.0.0.1:8001/dashboard', 'http://127.0.0.1:8002/dashboard'] as $url) {
            pclose(popen("start \"\" \"{$url}\"", 'r'));
        }

        $this->info('CashSync is running. Close the windows to stop it.');

        return self::SUCCESS;
    }
}