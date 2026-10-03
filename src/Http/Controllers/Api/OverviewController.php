<?php

namespace Headdetect\Overseer\Http\Controllers\Api;

use App\Models\Server;
use Headdetect\Overseer\Services\ConsoleService;
use Headdetect\Overseer\Services\Map\MapService;
use Headdetect\Overseer\Services\OverviewService;
use Headdetect\Overseer\Services\QuickCommands;
use Headdetect\Overseer\Support\Permission;
use Illuminate\Http\JsonResponse;

/** The Overview's stat cards, map setup, recent actions and the RCON warning. */
class OverviewController extends ApiController
{
    /** CPU, memory and disk, with sizes already formatted the way Pelican shows them. */
    public function stats(Server $server, OverviewService $overview): JsonResponse
    {
        $this->authorizeAny($server, Permission::MAP_VIEW);

        $stats = $overview->stats($server);
        $bytes = fn (?int $value) => $value === null ? null : convert_bytes_to_readable($value, 1);
        $r = $stats['resources'];

        return response()->json([
            'running' => $stats['running'],
            'cpu' => $r['cpu'],
            'cpu_limit' => $r['cpu_limit'],
            'memory' => $r['memory'],
            'memory_text' => $bytes($r['memory']),
            'memory_limit' => $r['memory_limit'],
            'memory_limit_text' => $r['memory_limit'] > 0 ? $bytes($r['memory_limit']) : null,
            'disk' => $r['disk'],
            'disk_text' => $bytes($r['disk']),
            'disk_limit' => $r['disk_limit'],
            'disk_limit_text' => $r['disk_limit'] > 0 ? $bytes($r['disk_limit']) : null,
        ]);
    }

    /** Where the map gets its tiles, and why there are none when squaremap isn't ready. */
    public function map(Server $server, MapService $map): JsonResponse
    {
        $this->authorizeAny($server, Permission::MAP_VIEW);

        return response()->json(self::mapConfig($map->source($server, fresh: request()->isMethod('post'))));
    }

    public function recent(Server $server, QuickCommands $commands): JsonResponse
    {
        $this->authorizeAny($server, Permission::COMMANDS_WORLD, Permission::COMMANDS_OPS);

        return response()->json(['lines' => $commands->recent($server)]);
    }

    /** Whether RCON is off, unreachable, or published on a public address. */
    public function rcon(Server $server, ConsoleService $console): JsonResponse
    {
        abort_unless(Permission::allows(Permission::MAP_VIEW, $server)
            || Permission::allows(Permission::PLAYERS_VIEW, $server)
            || Permission::allows(Permission::COMMANDS_WORLD, $server)
            || Permission::allows(Permission::COMMANDS_OPS, $server)
            || Permission::allows(Permission::CONFIG_VIEW, $server)
            || Permission::allows(Permission::CONFIG_EDIT, $server)
            || Permission::allows(Permission::TOOLS, $server), 403);

        return response()->json(['state' => $console->rconState($server), 'exposed' => $console->exposedRconPort($server)]);
    }

    /**
     * @param  array{status: string, port: ?int, worlds: array<int, array<string, mixed>>}  $source
     * @return array<string, mixed>
     */
    public static function mapConfig(array $source): array
    {
        $squaremap = $source['status'] === MapService::READY;

        return [
            'mode' => $squaremap ? 'squaremap' : 'grid',
            'worlds' => $squaremap ? self::labelWorlds($source['worlds']) : self::gridWorlds(),
            'setup' => $squaremap ? null : [
                'title' => trans("overseer::overseer.map.setup.{$source['status']}.title"),
                'body' => trans("overseer::overseer.map.setup.{$source['status']}.body", ['port' => $source['port'] ?? '']),
            ],
        ];
    }

    /**
     * squaremap labels worlds with their ids ("minecraft:the_nether") unless
     * its config names them, and lists them in no useful order. Use the
     * translated names for the vanilla dimensions, and put them first.
     *
     * @param  array<int, array<string, mixed>>  $worlds
     * @return array<int, array<string, mixed>>
     */
    private static function labelWorlds(array $worlds): array
    {
        $rank = ['overworld' => 0, 'nether' => 1, 'end' => 2];

        $worlds = array_map(fn (array $world) => [
            ...$world,
            'label' => isset($rank[$world['type']]) && str_contains($world['label'], ':')
                ? trans("overseer::overseer.map.worlds.{$world['type']}")
                : $world['label'],
        ], $worlds);
        usort($worlds, fn ($a, $b) => ($rank[$a['type']] ?? 3) <=> ($rank[$b['type']] ?? 3));

        return $worlds;
    }

    /**
     * The three vanilla dimensions, for the plain grid shown without squaremap.
     * Zoom 4 is one pixel per block; each step down halves it.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function gridWorlds(): array
    {
        $world = fn (string $name, string $type) => [
            'name' => $name,
            'label' => trans("overseer::overseer.map.worlds.$type"),
            'type' => $type,
            'max' => 4,
            'def' => 2,
            'extra' => 2,
            'spawn' => ['x' => 0, 'z' => 0],
        ];

        return [$world('overworld', 'overworld'), $world('the_nether', 'nether'), $world('the_end', 'end')];
    }
}
