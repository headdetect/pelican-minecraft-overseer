<?php

namespace Headdetect\Overseer\Support;

use App\Enums\SubuserPermission;
use App\Filament\Server\Resources\Files\Pages\EditFiles;
use App\Models\Server;
use Headdetect\Overseer\Filament\Server\Pages;
use Headdetect\Overseer\Services\ConsoleService;

/**
 * What the React app needs on its first render: the tabs this user can open,
 * what they may do, the API address and the translations. Every API route
 * checks the permissions again, so this only decides what the app shows.
 */
final class AppBoot
{
    /** Tab key => page class, in tab order. */
    public const PAGES = [
        'overview' => Pages\Overview::class,
        'players' => Pages\Players::class,
        'config' => Pages\Config::class,
        'tools' => Pages\Tools::class,
    ];

    /** @return array<string, mixed> */
    public static function payload(Server $server, string $tab): array
    {
        $can = fn (string $permission) => Permission::allows($permission, $server);

        $tabs = [];
        foreach (self::PAGES as $key => $page) {
            if ($page::canAccess()) {
                $tabs[] = [
                    'key' => $key,
                    'label' => $page::getNavigationLabel(),
                    'url' => $page::getUrl(),
                ];
            }
        }

        $console = app(ConsoleService::class);

        return [
            'tab' => $tab,
            'tabs' => $tabs,
            'cluster' => trans('overseer::overseer.cluster'),
            'api' => url("/overseer/servers/$server->uuid/api"),
            'feedUrl' => route('overseer.map.feed', ['server' => $server->uuid]),
            'surfaceUrl' => route('overseer.map.surface', ['server' => $server->uuid]),
            'tileBase' => url("/overseer/servers/$server->uuid/map") . '/',
            'headUrl' => 'https://mc-heads.net/avatar/{name}/64',
            // Pelican's file editor, with __PATH__ for the file.
            'fileEditorUrl' => EditFiles::getUrl(['path' => '__PATH__']),
            'reasons' => array_values(config('overseer.reasons', [])),
            'can' => [
                'mapView' => $can(Permission::MAP_VIEW),
                'playersView' => $can(Permission::PLAYERS_VIEW),
                'kick' => $can(Permission::PLAYERS_KICK),
                'ban' => $can(Permission::PLAYERS_BAN),
                'op' => $can(Permission::PLAYERS_OP),
                'whitelist' => $can(Permission::PLAYERS_WHITELIST),
                'cheat' => $can(Permission::PLAYERS_CHEAT),
                'commandsWorld' => $can(Permission::COMMANDS_WORLD),
                'commandsOps' => $can(Permission::COMMANDS_OPS),
                'configView' => $can(Permission::CONFIG_VIEW),
                'configEdit' => $can(Permission::CONFIG_EDIT),
                'tools' => $can(Permission::TOOLS),
                'restart' => (bool) user()?->can(SubuserPermission::ControlRestart, $server),
            ],
            'rcon' => [
                'state' => $console->rconState($server),
                'exposed' => $console->exposedRconPort($server),
            ],
            'lang' => trans('overseer::overseer'),
        ];
    }

    /** The bundle's URL with a version that changes when the file does, so browsers can cache it. */
    public static function assetUrl(string $file): string
    {
        $path = plugin_path('overseer', "resources/dist/$file");
        $version = is_file($path) ? substr(md5(filemtime($path) . ':' . filesize($path)), 0, 10) : '0';

        return route('overseer.asset', ['file' => $file]) . '?v=' . $version;
    }
}
