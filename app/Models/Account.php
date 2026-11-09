<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A bank account (one row of the "accounts" table).
 */
class Account extends Model
{
    // HasUuids: Laravel gives every new account a UUID as its id automatically
    use HasUuids;

    // Allow filling any column with Account::create([...])
    protected $guarded = [];

    // Never include the PIN hash when an account is turned into JSON
    protected $hidden = ['pin_hash'];

    // Always treat these columns as whole numbers
    protected $casts = ['balance' => 'integer', 'version' => 'integer'];
}