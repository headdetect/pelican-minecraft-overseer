<?php

namespace Headdetect\Overseer\Services;

use App\Models\Server;
use Headdetect\Overseer\Models\AuditEntry;
use Headdetect\Overseer\Support\CommandInput;
use Headdetect\Overseer\Support\Permission;
use InvalidArgumentException;

/**
 * The one-click commands on the Overview: time of day, weather, saving, a
 * broadcast and any command. Time, weather and save need commands-world.
 * Broadcast, any command and chat need commands-ops.
 */
class QuickCommands
{
    /** Name => [audit action, command]. Each needs commands-world. */
    public const FIXED = [
        'sunrise' => ['time', 'time set 0'],
        'noon' => ['time', 'time set noon'],
        'sunset' => ['time', 'time set 12000'],
        'midnight' => ['time', 'time set midnight'],
        'clear' => ['weather', 'weather clear'],
        'rain' => ['weather', 'weather rain'],
        'thunder' => ['weather', 'weather thunder'],
        'save' => ['save', 'save-all'],
    ];

    public function __construct(private readonly ConsoleService $console) {}

    /** The permission a command needs, or null for a name that isn't one. */
    public static function permission(string $name): ?string
    {
        return match (true) {
            isset(self::FIXED[$name]) => Permission::COMMANDS_WORLD,
            in_array($name, ['broadcast', 'custom'], true) => Permission::COMMANDS_OPS,
            default => null,
        };
    }

    /**
     * The audit action and command text for a quick command.
     *
     * @return array{0: string, 1: string}
     *
     * @throws InvalidArgumentException
     */
    public static function build(string $name, array $data = []): array
    {
        if (isset(self::FIXED[$name])) {
            return self::FIXED[$name];
        }

        return match ($name) {
            'broadcast' => ['broadcast', 'say ' . self::required(CommandInput::text((string) ($data['message'] ?? '')))],
            'custom' => ['custom', self::required(CommandInput::command((string) ($data['command'] ?? '')))],
            default => throw new InvalidArgumentException("Unknown command \"$name\"."),
        };
    }

    /**
     * Runs a quick command and returns the notification for it.
     *
     * @return array{title: string, body: ?string}
     */
    public function run(Server $server, string $name, array $data = []): array
    {
        [$action, $command] = self::build($name, $data);
        $reply = $this->console->run($server, $action, $command);

        return [
            'title' => trans('overseer::overseer.commands.sent', ['command' => '/' . $command]),
            'body' => $reply ?: null,
        ];
    }

    /** Sends a chat message as "[Rcon] <panel user>: message", with say. */
    public function chat(Server $server, string $message): void
    {
        $text = self::required(CommandInput::text($message));
        $name = CommandInput::text(user()?->username ?? 'admin', 32);

        $this->console->run($server, 'chat', "say $name: $text");
        cache()->forget("overseer:chat:$server->uuid");
    }

    /** @return string[] the latest audited commands, newest first */
    public function recent(Server $server): array
    {
        return AuditEntry::query()
            ->with('user')
            ->where('server_id', $server->id)
            ->latest('created_at')
            ->limit(config('overseer.recent_actions', 10))
            ->get()
            ->map(fn (AuditEntry $entry) => sprintf(
                '%s · %s · /%s%s',
                $entry->created_at->diffForHumans(short: true),
                $entry->user->username ?? trans('overseer::overseer.commands.recent.system'),
                $entry->command,
                $entry->response ? ' → ' . str($entry->response)->limit(80) : '',
            ))
            ->all();
    }

    private static function required(string $text): string
    {
        if ($text === '') {
            throw new InvalidArgumentException('Type something to send.');
        }

        return $text;
    }
}
