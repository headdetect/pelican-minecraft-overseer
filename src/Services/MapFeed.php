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
    ) {}

    /**
     * @return array{ok: bool, players: array<int, array<string, mixed>>, time: ?array<string, mixed>, chat: array<int, array<string, string>>, server: array<string, mixed>}
     */
    public function payload(Server $server): array
    {
        $running = $server->retrieveStatus() === ContainerStatus::Running;
        $players = [];
        if ($running) {
            $players = $this->map->source($server)['status'] === MapService::READY
                ? $this->map->players($server)
                : $this->rconPlayers($server);
        }

        // ops.json rarely changes, so read it at most every 30 seconds.
        $ops = Cache::remember("overseer:ops:$server->uuid", now()->addSeconds(30), fn () => $this->players->ops($server));
        $stats = $this->overview->stats($server);
        $uptime = $stats['resources']['uptime'];

        return [
            'ok' => $players !== null,
            'players' => array_map(fn (array $player) => [...$player, 'op' => in_array($player['name'], $ops, true)], $players ?? []),
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
