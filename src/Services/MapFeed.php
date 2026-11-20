<?php

namespace Headdetect\Overseer\Services;

use App\Enums\ContainerStatus;
use App\Models\Server;
use Headdetect\Overseer\Services\Map\MapService;
use Headdetect\Overseer\Support\ServerStats;
use Illuminate\Support\Facades\Cache;

/**
 * What the Overview's browser script polls every few seconds: player
 * positions, the game time, chat and server info.
 */
class MapFeed
{
    public function __construct(
        private readonly MapService $map,
        private readonly PlayerService $players,
        private readonly OverviewService $overview,
        private readonly ConsoleService $console,
    ) {}

    /**
     * @return array{ok: bool, players: array<int, array<string, mixed>>, time: ?array<string, mixed>, chat: array<int, array<string, string>>, server: array<string, mixed>}
     */
    public function payload(Server $server): array
    {
        $running = $server->retrieveStatus() === ContainerStatus::Running;
        $players = [];
        $unmapped = [];
        if ($running) {
            if ($this->usesSquaremap($server)) {
                $players = $this->map->players($server);
                $unmapped = $players !== null ? $this->unmapped($server, array_column($players, 'name')) : [];
            } else {
                $players = $this->rconPlayers($server);
            }
        }

        $ops = $this->ops($server);
        $stats = $this->overview->stats($server);
        $uptime = $stats['resources']['uptime'];

        // The sidebar badge shows the same count as Online now while someone has the Overview open.
        Cache::put("overseer:online-count:$server->uuid", count($players ?? []) + count($unmapped), now()->addSeconds(30));

        return [
            'ok' => $players !== null,
            'players' => array_map(fn (array $player) => [...$player, 'op' => in_array($player['name'], $ops, true)], $players ?? []),
            'unmapped' => array_map(fn (array $player) => [...$player, 'op' => in_array($player['name'], $ops, true)], $unmapped),
            'time' => $running ? $this->overview->gameTime($server) : null,
            'chat' => $running ? ($this->overview->chat($server) ?? []) : [],
            'server' => [
                'version' => $stats['version'],
                'modpack' => $stats['modpack'],
                'uptime' => $uptime !== null ? trans('overseer::overseer.overview.uptime', ['time' => ServerStats::uptime($uptime)]) : null,
            ],
        ];
    }

    /**
     * Only the map markers, for the stream to send between full feeds. With
     * squaremap this is one read of its players.json, without RCON. Null when
     * the server is off or nothing answered.
     *
     * @return ?array<int, array<string, mixed>>
     */
    public function positions(Server $server): ?array
    {
        if ($server->retrieveStatus() !== ContainerStatus::Running) {
            return null;
        }

        $players = $this->usesSquaremap($server) ? $this->map->players($server) : $this->rconPlayers($server);
        if ($players === null) {
            return null;
        }

        $ops = $this->ops($server);

        return array_map(fn (array $player) => [...$player, 'op' => in_array($player['name'], $ops, true)], $players);
    }

    /** Whether positions come from squaremap. Otherwise they cost several RCON queries per player. */
    public function usesSquaremap(Server $server): bool
    {
        return $this->map->source($server)['status'] === MapService::READY;
    }

    /** @return string[] ops.json rarely changes, so read it at most every 30 seconds. */
    private function ops(Server $server): array
    {
        return Cache::remember("overseer:ops:$server->uuid", now()->addSeconds(30), fn () => $this->players->ops($server));
    }

    /**
     * Online players squaremap leaves off its map, other than spectators.
     * squaremap hides dead players until they respawn, and those should still
     * show in Online now.
     *
     * @param  string[]  $mapped
     * @return array<int, array{name: string, dead: bool}>
     */
    private function unmapped(Server $server, array $mapped): array
    {
        $reply = $this->console->query($server, 'list');
        if ($reply === null) {
            return [];
        }

        $players = [];
        foreach (array_diff(PlayerService::parseList($reply), $mapped) as $name) {
            $mode = PlayerService::parseNumber((string) $this->console->query($server, "data get entity $name playerGameType"));
            if ($mode === 3) {
                continue;
            }
            $health = PlayerService::parseFloat((string) $this->console->query($server, "data get entity $name Health"));
            $players[] = ['name' => $name, 'dead' => $health !== null && $health <= 0];
        }

        return $players;
    }

    /**
     * Positions from RCON, for servers without squaremap.
     *
     * @return ?array<int, array<string, mixed>>
     */
    private function rconPlayers(Server $server): ?array
    {
        $online = $this->players->online($server);
        if ($online === null) {
            return null;
        }

        $players = [];
        foreach ($online as $player) {
            if ($player['x'] === null) {
                continue;
            }

            $players[] = [
                'name' => $player['name'],
                'world' => in_array($player['dimension'], ['the_nether', 'the_end'], true) ? $player['dimension'] : 'overworld',
                'x' => $player['x'],
                'y' => $player['y'],
                'z' => $player['z'],
                'yaw' => null,
                'health' => null,
            ];
        }

        return $players;
    }
}
