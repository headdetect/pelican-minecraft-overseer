<?php

namespace Headdetect\Overseer\Services\Map;

use App\Models\Server;
use Headdetect\Overseer\Services\Rcon\RconClient;
use Headdetect\Overseer\Services\Rcon\RconConnector;
use Headdetect\Overseer\Services\Rcon\RconException;
use Headdetect\Overseer\Support\CommandInput;
use InvalidArgumentException;

/**
 * Finds the ground height at a block column, for teleporting players to a
 * point picked on the map.
 *
 * Minecraft has no command that prints a height, so Overseer loads the chunk,
 * summons an invisible marker on top of the motion_blocking_no_leaves heightmap
 * (the highest block that stops movement, ignoring leaves), reads the marker's
 * Y and removes it again.
 *
 * It uses its own RCON connection with a longer timeout, because a busy
 * server (during chunk generation, for example) can take a few seconds to
 * answer, and it reconnects after a timeout instead of giving up.
 */
class Surface
{
    /** A marker in a chunk that was just loaded takes a moment to become findable. */
    private const READ_ATTEMPTS = 8;

    private const READ_WAIT_MS = 200;

    private const TIMEOUT = 5.0;

    /** Give up after this long, so a stuck server doesn't hold the page. */
    private const DEADLINE = 10.0;

    private ?RconClient $client = null;

    public function __construct(private readonly RconConnector $connector) {}

    /** The Y to stand on at x, z, or null when the server didn't answer in time. */
    public function y(Server $server, string $dimension, int $x, int $z): ?int
    {
        $dimension = self::checkDimension($dimension);
        $tag = 'overseer_probe_' . bin2hex(random_bytes(4));
        $marker = "@e[type=minecraft:marker,tag=$tag,limit=1]";
        $deadline = microtime(true) + self::DEADLINE;

        $this->send($server, "execute in $dimension run forceload add $x $z");
        try {
            $this->send($server, "execute in $dimension positioned $x 0 $z positioned over motion_blocking_no_leaves run summon minecraft:marker ~ ~ ~ {Tags:[\"$tag\"]}");

            for ($i = 0; $i < self::READ_ATTEMPTS && microtime(true) < $deadline; $i++) {
                $y = self::parseY((string) $this->send($server, "execute in $dimension run data get entity $marker Pos[1]"));
                if ($y !== null) {
                    return $y;
                }
                usleep(self::READ_WAIT_MS * 1000);
            }

            return null;
        } finally {
            $this->send($server, "execute in $dimension run kill @e[type=minecraft:marker,tag=$tag]");
            $this->send($server, "execute in $dimension run forceload remove $x $z");
            $this->client?->close();
            $this->client = null;
        }
    }

    /** Runs one command, or returns null and reconnects for the next one if it timed out. */
    private function send(Server $server, string $command): ?string
    {
        try {
            $this->client ??= $this->connector->connect($server, self::TIMEOUT);

            return CommandInput::stripFormatting($this->client->command($command));
        } catch (RconException) {
            $this->client?->close();
            $this->client = null;

            return null;
        }
    }

    /** Parses "Marker has the following entity data: 71.0d" into 71. */
    public static function parseY(string $reply): ?int
    {
        return preg_match('/entity data: (-?\d+(?:\.\d+)?)d/', $reply, $m) ? (int) floor((float) $m[1]) : null;
    }

    /**
     * The dimension id for a map world name. squaremap names worlds like
     * "minecraft_the_nether", and the block grid uses "the_nether".
     */
    public static function dimension(string $world): string
    {
        $id = str_contains($world, ':') ? $world : (str_starts_with($world, 'minecraft_') ? preg_replace('/_/', ':', $world, 1) : "minecraft:$world");

        return self::checkDimension($id);
    }

    private static function checkDimension(string $dimension): string
    {
        if (!in_array($dimension, CommandInput::DIMENSIONS, true)) {
            throw new InvalidArgumentException("\"$dimension\" isn't a dimension Overseer can teleport to.");
        }

        return $dimension;
    }
}
