<?php

namespace Headdetect\Overseer\Http\Controllers\Api;

use App\Enums\ContainerStatus;
use App\Models\Server;
use Headdetect\Overseer\Models\TimedBan;
use Headdetect\Overseer\Services\ConsoleService;
use Headdetect\Overseer\Services\Map\Surface;
use Headdetect\Overseer\Services\PlayerActions;
use Headdetect\Overseer\Services\PlayerService;
use Headdetect\Overseer\Support\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** The Players page list, and the actions on one player from that page and the map. */
class PlayerController extends ApiController
{
    /**
     * Everyone who has played, the ops, the whitelist and the bans. A manual
     * refresh (?fresh=1) reads the player files again instead of the cache.
     */
    public function index(Request $request, Server $server, PlayerService $players): JsonResponse
    {
        $this->authorizeAny($server, Permission::PLAYERS_VIEW);

        if ($request->boolean('fresh')) {
            $players->forgetRoster($server);
        }

        $online = $server->retrieveStatus() === ContainerStatus::Running ? $players->online($server) : [];
        $ops = $players->ops($server);
        $whitelist = $players->whitelist($server);
        $banned = $players->banned($server);
        $roster = $players->roster($server, array_column($online ?? [], 'name'));
        $details = $players->details($server, $roster);
        $timedBans = TimedBan::active()->where('server_id', $server->id)->pluck('expires_at', 'player');

        $positions = array_column($online ?? [], null, 'name');
        $byName = array_column($roster, null, 'name');
        $timezone = user()?->timezone ?? config('app.timezone');

        // For online players, use the values from RCON in place of what their file had at the last save.
        $row = function (string $name) use ($details, $positions, $byName, $timezone) {
            $lastSeen = $byName[$name]['last_seen'] ?? null;

            return [
                ...($details[$name] ?? []),
                ...array_filter($positions[$name] ?? [], fn ($value) => $value !== null),
                'name' => $name,
                'is_online' => $byName[$name]['online'] ?? isset($positions[$name]),
                'last_seen' => $lastSeen,
                'last_seen_text' => $lastSeen ? Carbon::createFromTimestamp($lastSeen)->diffForHumans() : null,
                'last_seen_title' => $lastSeen ? Carbon::createFromTimestamp($lastSeen)->timezone($timezone)->toDayDateTimeString() : null,
            ];
        };

        $names = array_values(array_unique([...array_column($roster, 'name'), ...$ops, ...$whitelist]));

        return response()->json([
            'rcon' => $online !== null,
            'roster' => array_column($roster, 'name'),
            'ops' => $ops,
            'whitelist' => $whitelist,
            'players' => (object) array_combine($names, array_map($row, $names)),
            'banned' => array_map(function (array $ban) use ($timedBans) {
                $at = $timedBans[$ban['name']] ?? null;

                return [
                    ...$ban,
                    'created' => $ban['created'] ? substr($ban['created'], 0, 10) : null,
                    // Expired but not lifted yet: the scheduler pardons within a minute while the server runs.
                    'expires_text' => match (true) {
                        $at === null => null,
                        $at->isPast() => trans('overseer::overseer.players.lifting_soon'),
                        default => $at->diffForHumans(),
                    },
                ];
            }, $banned),
        ]);
    }

    /** Names of the players online now, for the teleport forms. */
    public function online(Server $server, ConsoleService $console): JsonResponse
    {
        $this->authorizeAny($server, Permission::PLAYERS_CHEAT);

        $reply = $server->retrieveStatus() === ContainerStatus::Running ? $console->query($server, 'list') : null;

        return response()->json(['names' => $reply !== null ? PlayerService::parseList($reply) : []]);
    }

    /** The player's game mode, for the Game mode form's default. */
    public function gamemode(Server $server, string $player, PlayerActions $actions): JsonResponse
    {
        $this->authorizeAny($server, Permission::PLAYERS_CHEAT);

        return response()->json(['mode' => $actions->currentGameMode($server, $player)]);
    }

    public function act(Request $request, Server $server, string $player, string $action, PlayerActions $actions): JsonResponse
    {
        $permission = PlayerActions::PERMISSIONS[$action] ?? null;
        abort_if($permission === null, 404);
        $this->authorizeAny($server, $permission);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:200'],
            'duration' => ['nullable'],
            'mode' => ['nullable', 'string'],
            'item' => ['nullable', 'string', 'max:200', 'regex:/^(?:[a-z0-9_.-]+:)?[a-z0-9_.\/-]{1,100}$/i'],
            'count' => ['nullable', 'integer', 'between:1,6400'],
            'to' => ['nullable', 'in:player,coords'],
            'target' => ['nullable', 'string'],
            'x' => ['nullable', 'integer', 'between:-29999984,29999984'],
            'y' => ['nullable', 'integer', 'between:-2048,2048'],
            'z' => ['nullable', 'integer', 'between:-29999984,29999984'],
            'dimension' => ['nullable', 'string'],
            // A map world name, like minecraft_the_nether, in place of a dimension.
            'world' => ['nullable', 'string', 'max:64'],
        ]);

        return $this->attempt(trans('overseer::overseer.players.notifications.failed'), function () use ($actions, $server, $action, $player, $data) {
            if (!empty($data['world'])) {
                $data['dimension'] = Surface::dimension($data['world']);
            }

            return $actions->run($server, $action, $player, $data);
        });
    }
}
