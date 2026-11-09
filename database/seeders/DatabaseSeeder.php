<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\NodeEvent;
use App\Models\NodeState;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Ramsey\Uuid\Uuid;

/**
 * Fills a bank server's database with the demo accounts.
 * Bank A and Bank B both start with exactly the same accounts and balances.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // [account number, holder, starting balance in UGX, PIN]
        $accounts = [
            ['1001', 'Sarah Namuli', 150000, '1234'],
            ['1002', 'John Okello', 500000, '1234'],
            ['2002', 'Grace Atim', 75000, '1234'],
            ['3003', 'Musa Kato', 1000000, '1234'],
            ['9999', 'Test Account (race and load tests)', 150000, '0000'],
        ];

        foreach ($accounts as [$number, $name, $balance, $pin]) {
            Account::create([
                // Use the SAME UUID on A and B for each account, made from its number,
                // so the two servers' copies of an account match exactly.
                'id' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'cashsync-account-'.$number)->toString(),
                'account_no' => $number,
                'holder_name' => $name,
                'pin_hash' => Hash::make($pin),   // store only the hash of the PIN
                'balance' => $balance,
                'version' => 0,
            ]);
        }

        // This server's starting role comes from the settings file (A: primary, B: backup)
        $role = config('cashsync.start_role') === 'backup' ? 'backup' : 'primary';
        NodeState::create(['role' => $role, 'epoch' => 1]);

        NodeEvent::log('Server '.config('cashsync.node_name')." set up as {$role} (epoch 1)");
    }
}