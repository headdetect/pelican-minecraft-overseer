<?php

namespace Headdetect\Overseer\Support;

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

    /**
     * Whether a port bound to this IP may be reachable from the internet:
     * a bind-all address, or any address outside the private and reserved ranges.
     */
    public static function isPublic(string $ip): bool
    {
        if (in_array($ip, ['0.0.0.0', '::'], true)) {
            return true;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
