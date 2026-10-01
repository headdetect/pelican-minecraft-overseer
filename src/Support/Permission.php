<?php

namespace Headdetect\Underseer\Support;

use App\Models\Server;

/**
 * Subuser permission keys. Pelican stores them as "<group>.<permission>" and
 * shows them as their own tab on the server's Users page.
 */
final class Permission
{
    public const GROUP = 'underseer';

    public const MAP_VIEW = 'underseer.map-view';

    public const PLAYERS_VIEW = 'underseer.players-view';

    public const PLAYERS_KICK = 'underseer.players-kick';

    public const PLAYERS_BAN = 'underseer.players-ban';

    public const PLAYERS_OP = 'underseer.players-op';

    public const PLAYERS_WHITELIST = 'underseer.players-whitelist';

    public const COMMANDS_WORLD = 'underseer.commands-world';

    public const COMMANDS_OPS = 'underseer.commands-ops';

    public const CONFIG_VIEW = 'underseer.config-view';

    public const CONFIG_EDIT = 'underseer.config-edit';

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
