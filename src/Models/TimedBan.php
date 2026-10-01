<?php

namespace Headdetect\Overseer\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A ban Overseer will lift by itself. Minecraft has no built-in temporary ban,
 * so the player is banned normally and pardoned when this expires.
 *
 * @property int $id
 * @property int $server_id
 * @property ?int $user_id
 * @property string $player
 * @property ?string $reason
 * @property \Illuminate\Support\Carbon $expires_at
 * @property ?\Illuminate\Support\Carbon $lifted_at
 * @property-read Server $server
 */
class TimedBan extends Model
{
    protected $table = 'overseer_timed_bans';

    protected $fillable = ['server_id', 'user_id', 'player', 'reason', 'expires_at', 'lifted_at'];

    protected $casts = [
        'expires_at' => 'datetime',
        'lifted_at' => 'datetime',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @param Builder<TimedBan> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('lifted_at');
    }

    /** @param Builder<TimedBan> $query */
    public function scopeExpired(Builder $query): void
    {
        $query->whereNull('lifted_at')->where('expires_at', '<=', now());
    }
}
