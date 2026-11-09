<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The single row that describes THIS bank server: its role (primary or backup),
 * its epoch, and what it last heard from the other server.
 */
class NodeState extends Model
{
    use HasUuids;

    // The table name is "node_state" (Laravel would otherwise guess "node_states")
    protected $table = 'node_state';

    protected $guarded = [];

    protected $casts = [
        'epoch' => 'integer',
        'peer_epoch' => 'integer',
        'degraded' => 'boolean',
        'peer_seen_at' => 'datetime',
    ];

    /** Read this server's state (there is only ever one row). */
    public static function current(): self
    {
        return static::query()->firstOrFail();
    }

    /** Is this server the primary right now? */
    public function isPrimary(): bool
    {
        return $this->role === 'primary';
    }
}