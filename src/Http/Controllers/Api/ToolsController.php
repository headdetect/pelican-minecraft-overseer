<?php

namespace Headdetect\Overseer\Http\Controllers\Api;

use App\Enums\ContainerStatus;
use App\Models\Server;
use Headdetect\Overseer\Services\ConsoleService;
use Headdetect\Overseer\Services\Map\MapService;
use Headdetect\Overseer\Services\Tools\Chunky;
use Headdetect\Overseer\Support\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The Tools page: Chunky's chunk generation and squaremap's full redraw. */
class ToolsController extends ApiController
{
    /** Vanilla dimensions, for servers without squaremap to list the worlds. */
    private const DEFAULT_WORLDS = ['minecraft:overworld', 'minecraft:the_nether', 'minecraft:the_end'];

    public function show(Server $server, Chunky $chunky, MapService $map): JsonResponse
    {
        $this->authorizeAny($server, Permission::TOOLS);

        $status = $server->retrieveStatus();
        $running = $status === ContainerStatus::Running;
        $source = $map->source($server);

        return response()->json([
            'running' => $running,
            'starting' => $status === ContainerStatus::Starting,
            ...($running ? $chunky->status($server) : ['installed' => null, 'tasks' => [], 'saved' => []]),
            'squaremap' => $source['status'] === MapService::READY,
            'worlds' => $this->worlds($source),
            'max_radius' => Chunky::MAX_RADIUS,
        ]);
    }

    public function chunky(Request $request, Server $server, string $action, Chunky $chunky, MapService $map): JsonResponse
    {
        $this->authorizeAny($server, Permission::TOOLS);
        abort_unless(in_array($action, ['start', 'pause', 'continue', 'cancel'], true), 404);

        if ($action === 'start') {
            $data = $request->validate([
                'world' => ['required', 'string', 'in:' . implode(',', $this->worlds($map->source($server)))],
                'radius' => ['required', 'integer', 'between:16,' . Chunky::MAX_RADIUS],
                'shape' => ['required', 'in:' . implode(',', Chunky::SHAPES)],
                'spawn' => ['required', 'boolean'],
                'x' => ['exclude_if:spawn,true', 'required', 'integer', 'between:-29999984,29999984'],
                'z' => ['exclude_if:spawn,true', 'required', 'integer', 'between:-29999984,29999984'],
            ]);
        }

        return $this->attempt(trans('overseer::overseer.tools.failed'), fn () => self::done(match ($action) {
            'start' => $chunky->start($server, $data['world'], $data['spawn'] ? null : (int) $data['x'], $data['spawn'] ? null : (int) $data['z'], (int) $data['radius'], $data['shape']),
            'pause' => $chunky->pause($server),
            'continue' => $chunky->continue($server),
            'cancel' => $chunky->cancel($server),
        }));
    }

    public function render(Request $request, Server $server, ConsoleService $console, MapService $map): JsonResponse
    {
        $this->authorizeAny($server, Permission::TOOLS);
        $data = $request->validate(['world' => ['required', 'string', 'in:' . implode(',', $this->worlds($map->source($server)))]]);

        return $this->attempt(trans('overseer::overseer.tools.failed'), fn () => self::done(
            $console->run($server, 'render', "squaremap fullrender {$data['world']}"),
        ));
    }

    /** @return array{title: string, body: ?string} */
    private static function done(?string $reply): array
    {
        return ['title' => trans('overseer::overseer.tools.sent'), 'body' => $reply ? preg_replace('/^\[Chunky\]\s*/', '', $reply) : null];
    }

    /** @return string[] world ids Chunky and squaremap accept */
    private function worlds(array $source): array
    {
        $ids = $source['status'] === MapService::READY
            // squaremap names worlds like minecraft_the_nether.
            ? array_map(fn (array $world) => preg_replace('/_/', ':', $world['name'], 1), $source['worlds'])
            : self::DEFAULT_WORLDS;

        return array_values(array_filter($ids, fn ($id) => Chunky::isWorld($id)));
    }
}
