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
     * The host players connect to: the allocation's alias, or the node's FQDN.
     * Unlike host(), never a private IP the panel happens to reach the server on.
     */
    public static function publicHost(Server $server): string
    {
        return $server->allocation?->ip_alias ?: $server->node->fqdn;
    }

    public static function publicPort(Server $server): int
    {
        return $server->allocation?->port ?? 25565;
    }

    /** "host:port" for players to type, without the port when it's Minecraft's default. */
    public static function join(string $host, int $port): string
    {
        return $port === 25565 ? $host : "$host:$port";
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
