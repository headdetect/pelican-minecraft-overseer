<?php

namespace Headdetect\Underseer\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use Illuminate\Contracts\Filesystem\FileNotFoundException;

/**
 * Who is online (over RCON) and who is on the ops, whitelist and ban lists (from the server's JSON files).
 */
class PlayerService
{
    public function __construct(private readonly ConsoleService $console) {}

    /**
     * Online players with their position, or null when RCON isn't available.
     *
     * @return ?array<int, array{name: string, x: ?int, y: ?int, z: ?int, dimension: ?string}>
     */
    public function online(Server $server): ?array
    {
        $reply = $this->console->query($server, 'list');
        if ($reply === null) {
            return null;
        }

        $players = [];
        foreach (self::parseList($reply) as $name) {
            $position = self::parsePosition((string) $this->console->query($server, "data get entity $name Pos"));
            $dimension = self::parseDimension((string) $this->console->query($server, "data get entity $name Dimension"));

            $players[] = [
                'name' => $name,
                'x' => $position[0] ?? null,
                'y' => $position[1] ?? null,
                'z' => $position[2] ?? null,
                'dimension' => $dimension,
            ];
        }

        return $players;
    }

    /** @return string[] names in ops.json */
    public function ops(Server $server): array
    {
        return array_column($this->readJson($server, 'ops.json'), 'name');
    }

    /** @return string[] names in whitelist.json */
    public function whitelist(Server $server): array
    {
        return array_column($this->readJson($server, 'whitelist.json'), 'name');
    }

    /** @return array<int, array{name: string, reason: ?string, source: ?string, created: ?string, expires: ?string}> */
    public function banned(Server $server): array
    {
        return array_map(fn (array $ban) => [
            'name' => $ban['name'] ?? '?',
            'reason' => $ban['reason'] ?? null,
            'source' => $ban['source'] ?? null,
            'created' => $ban['created'] ?? null,
            'expires' => ($ban['expires'] ?? 'forever') === 'forever' ? null : $ban['expires'],
        ], $this->readJson($server, 'banned-players.json'));
    }

    /**
     * Everyone the server has seen, newest first.
     *
     * @return string[]
     */
    public function known(Server $server): array
    {
        $cache = $this->readJson($server, 'usercache.json');
        usort($cache, fn ($a, $b) => strcmp($b['expiresOn'] ?? '', $a['expiresOn'] ?? ''));

        return array_values(array_unique(array_column($cache, 'name')));
    }

    /**
     * Parses the reply to `list`, e.g. "There are 2 of a max of 20 players online: Doobie, kelp_lord".
     *
     * @return string[]
     */
    public static function parseList(string $reply): array
    {
        if (!preg_match('/players online:(.*)$/is', $reply, $match)) {
            return [];
        }

        $names = array_map('trim', explode(',', $match[1]));

        return array_values(array_filter($names, fn ($name) => preg_match('/^[.*]?[A-Za-z0-9_]{1,16}$/', $name)));
    }

    /**
     * Parses "Doobie has the following entity data: [212.5d, 71.0d, -140.3d]".
     *
     * @return ?array{int, int, int}
     */
    public static function parsePosition(string $reply): ?array
    {
        if (!preg_match('/\[(-?[\d.]+)d, (-?[\d.]+)d, (-?[\d.]+)d\]/', $reply, $m)) {
            return null;
        }

        return [(int) floor((float) $m[1]), (int) floor((float) $m[2]), (int) floor((float) $m[3])];
    }

    /** Parses 'Doobie has the following entity data: "minecraft:the_nether"' into "the_nether". */
    public static function parseDimension(string $reply): ?string
    {
        if (!preg_match('/"(?:[a-z0-9_.-]+:)?([a-z0-9_\/.-]+)"/', $reply, $m)) {
            return null;
        }

        return $m[1];
    }

    /** @return array<int, array<string, mixed>> */
    private function readJson(Server $server, string $file): array
    {
        try {
            $contents = (new DaemonFileRepository())->setServer($server)->getContent($file);
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

            return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
        } catch (FileNotFoundException) {
            return []; // A fresh server has no list files yet.
        } catch (Exception $exception) {
            report($exception);

            return [];
        }
    }
}
