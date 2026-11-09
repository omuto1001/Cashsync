<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One processed ATM request (one row of the "transactions" table).
 */
class Transaction extends Model
{
    // Every new transaction gets a UUID. Laravel's UUIDs are time-ordered,
    // so sorting by id also sorts by time.
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'amount' => 'integer',
        'balance_after' => 'integer',
        'to_balance_after' => 'integer',
        'epoch' => 'integer',
    ];
}