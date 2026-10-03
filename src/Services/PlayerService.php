<?php

namespace Headdetect\Overseer\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use Headdetect\Overseer\Services\Rcon\RconConnector;
use Headdetect\Overseer\Support\CommandInput;
use Headdetect\Overseer\Support\PlayerNbt;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;

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
            $mode = self::parseNumber((string) $this->console->query($server, "data get entity $name playerGameType"));
            $level = self::parseNumber((string) $this->console->query($server, "data get entity $name XpLevel"));

            $players[] = [
                'name' => $name,
                'x' => $position[0] ?? null,
                'y' => $position[1] ?? null,
                'z' => $position[2] ?? null,
                'dimension' => $dimension,
                'gamemode' => $mode !== null ? (CommandInput::GAME_MODES[$mode] ?? null) : null,
                'xp_level' => $level,
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
     * Everyone who has played on the server, plus the whitelist, with when each
     * was last online. Online players come first, then the most recent.
     *
     * @param  string[]  $online  names of players online now
     * @return array<int, array{name: string, uuid: ?string, last_seen: ?int, online: bool}>
     */
    /** Drops the cached roster, after a change to the whitelist or bans or a manual refresh. */
    public function forgetRoster(Server $server): void
    {
        Cache::forget("overseer:roster:$server->uuid");
    }

    public function roster(Server $server, array $online = []): array
    {
        $base = Cache::remember("overseer:roster:$server->uuid", now()->addSeconds(30), function () use ($server) {
            [$lastSeen, $paths] = $this->lastSeen($server);

            return [
                'usercache' => $this->readJson($server, 'usercache.json'),
                'whitelist' => $this->readJson($server, 'whitelist.json'),
                'last_seen' => $lastSeen,
                'paths' => $paths,
            ];
        });

        return self::buildRoster($base['usercache'], $base['whitelist'], $base['last_seen'], $online);
    }

    /**
     * Combines the server's player records into one list.
     *
     * The server writes <uuid>.dat in the world's player data folder while a
     * player is online and when they leave, so its modified time is when they
     * were last online. A whitelisted player who never joined has no file.
     *
     * @param  array<int, array<string, mixed>>  $usercache  usercache.json, to name the uuids
     * @param  array<int, array<string, mixed>>  $whitelist  whitelist.json
     * @param  array<string, int>  $lastSeen  lowercase uuid => unix time
     * @param  string[]  $online
     * @return array<int, array{name: string, uuid: ?string, last_seen: ?int, online: bool}>
     */
    public static function buildRoster(array $usercache, array $whitelist, array $lastSeen, array $online): array
    {
        $names = [];
        foreach ([...$usercache, ...$whitelist] as $entry) {
            if (is_string($entry['uuid'] ?? null) && is_string($entry['name'] ?? null)) {
                $names[strtolower($entry['uuid'])] ??= $entry['name'];
            }
        }

        $players = [];
        $add = function (string $name, ?string $uuid) use (&$players, $lastSeen, $online) {
            $key = strtolower($name);
            $players[$key] ??= [
                'name' => $name,
                'uuid' => $uuid,
                'last_seen' => $uuid !== null ? ($lastSeen[strtolower($uuid)] ?? null) : null,
                'online' => in_array($key, array_map('strtolower', $online), true),
            ];
        };

        foreach ($lastSeen as $uuid => $time) {
            if (isset($names[$uuid])) {
                $add($names[$uuid], $uuid);
            }
        }
        foreach ($whitelist as $entry) {
            if (is_string($entry['name'] ?? null)) {
                $add($entry['name'], is_string($entry['uuid'] ?? null) ? $entry['uuid'] : null);
            }
        }
        foreach ($online as $name) {
            $add($name, array_search($name, $names, true) ?: null);
        }

        $players = array_values($players);
        usort($players, fn ($a, $b) => [$b['online'], $b['last_seen'] ?? -1, strtolower($a['name'])] <=> [$a['online'], $a['last_seen'] ?? -1, strtolower($b['name'])]);

        return $players;
    }

    /**
     * When each player was last online, from the modified time of their player
     * data file. Minecraft 26.1 and newer use <world>/players/data, older
     * versions <world>/playerdata.
     *
     * @return array{array<string, int>, array<string, string>} lowercase uuid => unix time, and uuid => file path
     */
    private function lastSeen(Server $server): array
    {
        $world = app(RconConnector::class)->properties($server)['level-name'] ?? '' ?: 'world';
        $files = (new DaemonFileRepository())->setServer($server);
        $seen = [];
        $paths = [];

        foreach (["$world/players/data", "$world/playerdata"] as $directory) {
            try {
                $entries = $files->getDirectory($directory);
            } catch (RequestException $exception) {
                if ($exception->response->status() !== 404) {
                    report($exception);
                }

                continue;
            } catch (Exception $exception) {
                report($exception);

                continue;
            }

            foreach ($entries as $entry) {
                if (preg_match('/^([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\.dat$/i', $entry['name'] ?? '', $m)
                    && ($time = strtotime((string) ($entry['modified'] ?? '')))) {
                    $uuid = strtolower($m[1]);
                    if ($time >= ($seen[$uuid] ?? 0)) {
                        $seen[$uuid] = $time;
                        $paths[$uuid] = "$directory/{$entry['name']}";
                    }
                }
            }
        }

        return [$seen, $paths];
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

    /** Parses "Doobie has the following entity data: 20.0f" into 20.0. */
    public static function parseFloat(string $reply): ?float
    {
        return preg_match('/entity data: (-?\d+(?:\.\d+)?)[bsfdL]?\s*$/', trim($reply), $m) ? (float) $m[1] : null;
    }

    /** Parses "Doobie has the following entity data: 30" into 30. */
    public static function parseNumber(string $reply): ?int
    {
        return preg_match('/entity data: (-?\d+)[bsL]?\s*$/', trim($reply), $m) ? (int) $m[1] : null;
    }

    /**
     * Game mode, XP level and dimension for players who aren't online, from
     * their player data files. Each file is read once per change: the cache
     * key includes the time the server last wrote it.
     *
     * @param  array<int, array{name: string, uuid: ?string, last_seen: ?int}>  $roster
     * @return array<string, array{gamemode: ?string, xp_level: ?int, dimension: ?string}> by player name
     */
    public function details(Server $server, array $roster): array
    {
        $paths = Cache::get("overseer:roster:$server->uuid")['paths'] ?? [];
        $files = (new DaemonFileRepository())->setServer($server);
        $details = [];

        foreach ($roster as $player) {
            $uuid = strtolower((string) $player['uuid']);
            $path = $paths[$uuid] ?? null;
            if (!$path || !$player['last_seen']) {
                continue;
            }

            // One key per player holding the file's time, so a changed file replaces it instead of piling up.
            $key = "overseer:player-nbt:$server->uuid:$uuid";
            $cached = Cache::get($key);
            if (!is_array($cached) || ($cached['time'] ?? null) !== $player['last_seen']) {
                try {
                    $summary = PlayerNbt::summary($files->getContent($path));
                } catch (Exception $exception) {
                    report($exception);
                    $summary = null;
                }
                $cached = ['time' => $player['last_seen'], 'summary' => $summary];
                Cache::put($key, $cached, now()->addWeek());
            }

            if ($cached['summary']) {
                $details[$player['name']] = $cached['summary'];
            }
        }

        return $details;
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
