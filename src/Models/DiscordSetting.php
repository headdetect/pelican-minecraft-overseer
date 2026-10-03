<?php

namespace Headdetect\Overseer\Models;

use App\Models\Server;
use Headdetect\Overseer\Support\ServerAddress;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which Discord a server is linked to, and what /info tells players.
 *
 * @property int $id
 * @property int $server_id
 * @property ?string $guild_id
 * @property ?string $guild_name
 * @property ?string $address
 * @property ?int $port
 * @property ?string $info
 * @property-read Server $server
 */
class DiscordSetting extends Model
{
    protected $table = 'overseer_discord';

    protected $fillable = ['server_id', 'guild_id', 'guild_name', 'address', 'port', 'info'];

    protected $casts = [
        'port' => 'integer',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public static function for(Server $server): self
    {
        return self::query()->firstOrNew(['server_id' => $server->id]);
    }

    /** The address players connect to, as /info shows it. */
    public function joinAddress(): string
    {
        return ServerAddress::join(
            $this->address ?: ServerAddress::publicHost($this->server),
            $this->port ?: ServerAddress::publicPort($this->server),
        );
    }
}
