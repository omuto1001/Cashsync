<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * An important event (promotion, crash, catch-up ...), shown on the dashboard.
 */
class NodeEvent extends Model
{
    use HasUuids;

    protected $guarded = [];

    // This table only has created_at (no updated_at), so we manage the time ourselves
    public $timestamps = false;

    protected $casts = ['created_at' => 'datetime'];

    /** Save an event to the database and also write it to storage/logs/laravel.log */
    public static function log(string $message, string $level = 'info'): void
    {
        static::create(['message' => $message, 'level' => $level, 'created_at' => now()]);
        Log::info('[CashSync '.config('cashsync.node_name').'] '.$message);
    }
}