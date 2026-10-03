<?php

namespace Headdetect\Overseer\Models;

use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $server_id
 * @property ?int $user_id
 * @property ?string $discord_user_id
 * @property ?string $discord_name
 * @property string $action
 * @property ?string $target
 * @property string $command
 * @property ?string $response
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read ?User $user
 */
class AuditEntry extends Model
{
    protected $table = 'overseer_audit';

    public const UPDATED_AT = null;

    protected $fillable = ['server_id', 'user_id', 'discord_user_id', 'discord_name', 'action', 'target', 'command', 'response'];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
