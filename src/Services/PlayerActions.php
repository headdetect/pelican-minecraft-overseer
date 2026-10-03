<?php

namespace Headdetect\Overseer\Services;

use App\Models\Server;
use Exception;
use Headdetect\Overseer\Models\TimedBan;
use Headdetect\Overseer\Support\CommandInput;
use Headdetect\Overseer\Support\Permission;
use InvalidArgumentException;
use RuntimeException;

/**
 * The actions on one player: kick, ban, op, whitelist, game mode, give and
 * teleport. The Overview map and the Players page both call these through
 * the player API, which checks the permission in PERMISSIONS first.
 */
class PlayerActions
{
    /** Action => the permission it needs. */
    public const PERMISSIONS = [
        'kick' => Permission::PLAYERS_KICK,
        'ban' => Permission::PLAYERS_BAN,
        'unban' => Permission::PLAYERS_BAN,
        'op' => Permission::PLAYERS_OP,
        'deop' => Permission::PLAYERS_OP,
        'whitelist-add' => Permission::PLAYERS_WHITELIST,
        'whitelist-remove' => Permission::PLAYERS_WHITELIST,
        'gamemode' => Permission::PLAYERS_CHEAT,
        'give' => Permission::PLAYERS_CHEAT,
        'teleport' => Permission::PLAYERS_CHEAT,
    ];

    /** Ban lengths the ban form offers, in hours. */
    public const BAN_HOURS = [1 => 'hour', 24 => 'day', 168 => 'week'];

    public function __construct(
        private readonly ConsoleService $console,
        private readonly PlayerService $players,
    ) {}

    /**
     * Runs one action and returns the notification for it.
     *
     * @param  array<string, mixed>  $data  the action's form values
     * @return array{title: string, body: ?string}
     *
     * @throws InvalidArgumentException when the input is not valid
     * @throws RuntimeException when the command fails or Minecraft refuses it
     */
    public function run(Server $server, string $action, string $player, array $data = []): array
    {
        $name = CommandInput::playerName($player);

        [$audit, $command, $notification] = match ($action) {
            'kick' => ['kick', trim("kick $name " . CommandInput::text((string) ($data['reason'] ?? ''))), 'kicked'],
            'ban' => ['ban', trim("ban $name " . self::banReason(CommandInput::text((string) ($data['reason'] ?? '')), self::banHours($data['duration'] ?? null))), 'banned'],
            'unban' => ['unban', "pardon $name", 'unbanned'],
            'op' => ['op', "op $name", 'opped'],
            'deop' => ['deop', "deop $name", 'deopped'],
            'whitelist-add' => ['whitelist', "whitelist add $name", 'whitelist_added'],
            'whitelist-remove' => ['whitelist', "whitelist remove $name", 'whitelist_removed'],
            'gamemode' => ['gamemode', sprintf('gamemode %s %s', CommandInput::gameMode((string) ($data['mode'] ?? '')), $name), 'gamemode_changed'],
            'give' => ['give', sprintf('give %s %s %d', $name, CommandInput::itemId((string) ($data['item'] ?? '')), max(1, min(6400, (int) ($data['count'] ?? 1)))), 'given'],
            'teleport' => ['teleport', CommandInput::teleport($name, $data), 'teleported'],
            default => throw new InvalidArgumentException("Unknown action \"$action\"."),
        };

        $reply = $this->console->run($server, $audit, $command, $name);
        if (self::refused($reply)) {
            throw new RuntimeException((string) $reply);
        }

        $this->afterRun($server, $action, $name, $data);

        return [
            'title' => trans("overseer::overseer.players.notifications.$notification", ['name' => $name]),
            'body' => $reply ?: null,
        ];
    }

    /** The player's game mode over RCON, for the Game mode form's default. */
    public function currentGameMode(Server $server, string $player): string
    {
        try {
            $name = CommandInput::playerName($player);
            $mode = PlayerService::parseNumber((string) $this->console->query($server, "data get entity $name playerGameType"));

            return CommandInput::GAME_MODES[$mode] ?? 'survival';
        } catch (Exception) {
            return 'survival';
        }
    }

    /** Replies where Minecraft didn't do what was asked, though the command ran. */
    public static function refused(?string $reply): bool
    {
        return $reply !== null && (bool) preg_match('/^(Nothing changed|That player does not exist|No player was found|Unknown or incomplete command|Incorrect argument)/i', $reply);
    }

    /** Hours for a ban duration from the form, or null for a ban until unbanned. */
    public static function banHours(mixed $duration): ?int
    {
        $hours = is_numeric($duration) ? (int) $duration : null;

        return isset(self::BAN_HOURS[$hours]) ? $hours : null;
    }

    /** The reason the player sees, with the ban length added for a timed ban. */
    public static function banReason(string $reason, ?int $hours): string
    {
        if ($hours === null) {
            return $reason;
        }

        $duration = trans('overseer::overseer.players.durations.' . self::BAN_HOURS[$hours]);

        return trim($reason . ' (' . trans('overseer::overseer.players.ban_for', ['duration' => $duration]) . ')');
    }

    /** Keeps timed bans and the cached roster in step with what just ran. */
    private function afterRun(Server $server, string $action, string $name, array $data): void
    {
        if (in_array($action, ['ban', 'unban'], true)) {
            // A new ban replaces any earlier timed one, so the scheduler won't lift it.
            TimedBan::active()->where('server_id', $server->id)->where('player', $name)->update(['lifted_at' => now()]);
        }

        if ($action === 'ban' && ($hours = self::banHours($data['duration'] ?? null)) !== null) {
            $reason = CommandInput::text((string) ($data['reason'] ?? ''));
            TimedBan::create([
                'server_id' => $server->id,
                'user_id' => user()?->id,
                'player' => $name,
                'reason' => $reason ?: null,
                'expires_at' => now()->addHours($hours),
            ]);
        }

        if (in_array($action, ['op', 'deop'], true)) {
            cache()->forget("overseer:ops:$server->uuid");
        }

        if (in_array($action, ['whitelist-add', 'whitelist-remove', 'ban', 'unban'], true)) {
            $this->players->forgetRoster($server);
        }
    }
}
