<?php

namespace Headdetect\Underseer\Support;

use App\Models\Server;

/**
 * The host the panel dials to reach a port on a game server (RCON, the map's web server).
 */
final class ServerAddress
{
    public static function host(Server $server, ?string $override = null): string
    {
        if ($override) {
            return $override;
        }

        $ip = $server->allocation?->ip;

        // A bind-all address can't be dialled; the node's address reaches the same machine.
        if (!$ip || in_array($ip, ['0.0.0.0', '::'], true)) {
            return $server->node->fqdn;
        }

        return is_ipv6($ip) ? "[$ip]" : $ip;
    }
}
