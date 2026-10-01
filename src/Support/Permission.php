<?php

namespace Headdetect\Overseer\Support;

use App\Models\Server;

/**
 * Subuser permission keys. Pelican stores them as "<group>.<permission>" and
 * shows them as their own tab on the server's Users page.
 */
final class Permission
{
    public const GROUP = 'overseer';

    public const MAP_VIEW = 'overseer.map-view';

    public const PLAYERS_VIEW = 'overseer.players-view';

    public const PLAYERS_KICK = 'overseer.players-kick';

    public const PLAYERS_BAN = 'overseer.players-ban';

    public const PLAYERS_OP = 'overseer.players-op';

    public const PLAYERS_WHITELIST = 'overseer.players-whitelist';

    public const COMMANDS_WORLD = 'overseer.commands-world';

    public const COMMANDS_OPS = 'overseer.commands-ops';

    public const CONFIG_VIEW = 'overseer.config-view';

    public const CONFIG_EDIT = 'overseer.config-edit';

    /** @return string[] permission names without the group prefix, as Pelican registers them */
    public static function names(): array
    {
        return array_map(
            fn (string $key) => substr($key, strlen(self::GROUP) + 1),
            [
                self::MAP_VIEW,
                self::PLAYERS_VIEW,
                self::PLAYERS_KICK,
                self::PLAYERS_BAN,
                self::PLAYERS_OP,
                self::PLAYERS_WHITELIST,
                self::COMMANDS_WORLD,
                self::COMMANDS_OPS,
                self::CONFIG_VIEW,
                self::CONFIG_EDIT,
            ],
        );
    }

    public static function allows(string $permission, Server $server): bool
    {
        return (bool) user()?->can($permission, $server);
    }
}
