<?php

namespace Headdetect\Overseer\Services\Discord;

use App\Enums\ContainerStatus;
use App\Models\Server;
use Closure;
use Headdetect\Overseer\Models\AuditEntry;
use Headdetect\Overseer\Models\DiscordSetting;
use Headdetect\Overseer\Services\ConsoleService;
use Headdetect\Overseer\Services\OverviewService;
use Headdetect\Overseer\Services\PlayerService;
use Headdetect\Overseer\Support\CommandInput;
use Headdetect\Overseer\Support\DiscordEmbeds;
use Headdetect\Overseer\Support\ServerStats;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Answers /status, /info and /whitelist.
 *
 * Discord drops an interaction the panel doesn't answer within 3 seconds,
 * and each command asks Wings, RCON or Mojang for something. So the panel
 * answers "thinking" right away, and a deferred callback, which Laravel
 * runs after the response is sent, edits the reply when the work is done.
 */
class InteractionHandler
{
    private const REPLY = 4;

    private const THINKING = 5;

    private const EPHEMERAL = 64;

    public function __construct(
        private readonly DiscordApi $discord,
        private readonly ConsoleService $console,
        private readonly PlayerService $players,
        private readonly OverviewService $overview,
    ) {}

    /**
     * @param  array<string, mixed>  $interaction
     * @return array<string, mixed> the immediate response
     */
    public function handle(array $interaction): array
    {
        $setting = DiscordSetting::query()
            ->with('server')
            ->where('guild_id', (string) ($interaction['guild_id'] ?? ''))
            ->first();

        if (!$setting?->server) {
            return [
                'type' => self::REPLY,
                'data' => DiscordEmbeds::text($this->text()['reply']['not_set_up']) + ['flags' => self::EPHEMERAL],
            ];
        }

        $token = (string) $interaction['token'];
        $user = $interaction['member']['user'] ?? [];
        $actor = [
            'id' => (string) ($user['id'] ?? ''),
            'name' => (string) ($user['global_name'] ?? $user['username'] ?? '?'),
        ];

        return match ($interaction['data']['name'] ?? null) {
            'info' => $this->later($token, false, fn () => $this->info($setting)),
            'status' => $this->later($token, false, fn () => $this->status($setting->server)),
            'whitelist' => $this->later($token, true, fn () => $this->whitelist($setting->server, $this->option($interaction, 'name'), $actor)),
            default => ['type' => self::REPLY, 'data' => DiscordEmbeds::text($this->text()['reply']['failed']) + ['flags' => self::EPHEMERAL]],
        };
    }

    /** @return array<string, mixed> */
    private function info(DiscordSetting $setting): array
    {
        $stats = $this->overview->stats($setting->server);

        return DiscordEmbeds::info(
            $setting->server->name,
            $setting->joinAddress(),
            $stats['version'],
            $stats['modpack'],
            $setting->info,
            $this->text(),
        );
    }

    /** @return array<string, mixed> */
    private function status(Server $server): array
    {
        $stats = $this->overview->stats($server);
        $list = $stats['running'] ? $this->console->query($server, 'list') : null;
        $uptime = $stats['resources']['uptime'];

        return DiscordEmbeds::status(
            $server->name,
            $stats['running'],
            $uptime !== null ? ServerStats::uptime($uptime) : null,
            $list,
            $list !== null ? PlayerService::parseList($list) : [],
            $this->text(),
        );
    }

    /**
     * @param  array{id: string, name: string}  $actor
     * @return array<string, mixed>
     */
    private function whitelist(Server $server, string $name, array $actor): array
    {
        $text = $this->text();
        $name = trim($name);

        if (!CommandInput::isJavaName($name)) {
            return $this->rejected($server, $name, $actor, 'bedrock', $text['reply']['bedrock']);
        }

        try {
            $profile = $this->players->profile($name);
        } catch (Throwable $exception) {
            report($exception);

            return $this->rejected($server, $name, $actor, 'lookup_failed', $text['reply']['lookup_failed']);
        }

        if ($profile === null) {
            return $this->rejected($server, $name, $actor, 'no_account', strtr($text['reply']['no_account'], [':name' => DiscordEmbeds::escape($name)]));
        }

        if ($server->retrieveStatus() !== ContainerStatus::Running) {
            return $this->rejected($server, $profile['name'], $actor, 'offline', $text['reply']['offline']);
        }

        $reply = $this->console->run($server, 'whitelist', "whitelist add {$profile['name']}", $profile['name'], $actor);

        return DiscordEmbeds::whitelisted($profile, $reply, $text);
    }

    /**
     * A /whitelist that never reached the server. Logged like the ones that
     * did, so the Discord tab shows every attempt.
     *
     * @param  array{id: string, name: string}  $actor
     * @return array<string, mixed>
     */
    private function rejected(Server $server, string $name, array $actor, string $reason, string $message): array
    {
        AuditEntry::create([
            'server_id' => $server->id,
            'discord_user_id' => $actor['id'],
            'discord_name' => mb_substr($actor['name'], 0, 64),
            'action' => 'whitelist_rejected',
            'target' => mb_substr($name, 0, 64),
            'command' => 'whitelist add ' . CommandInput::text($name),
            'response' => trans("overseer::overseer.discord.rejected.$reason"),
        ]);

        return DiscordEmbeds::text($message);
    }

    /**
     * Sends "thinking" now, and edits the reply after the response is sent.
     *
     * @param  Closure(): array<string, mixed>  $work
     * @return array<string, mixed>
     */
    private function later(string $token, bool $private, Closure $work): array
    {
        defer(function () use ($token, $work) {
            try {
                $message = $work();
            } catch (Throwable $exception) {
                report($exception);
                $message = DiscordEmbeds::text($this->text()['reply']['failed']);
            }

            try {
                $this->discord->editReply($token, $message);
            } catch (Throwable $exception) {
                report($exception);
            }
        });

        return ['type' => self::THINKING] + ($private ? ['data' => ['flags' => self::EPHEMERAL]] : []);
    }

    /** @param array<string, mixed> $interaction */
    private function option(array $interaction, string $name): string
    {
        foreach ($interaction['data']['options'] ?? [] as $option) {
            if (($option['name'] ?? null) === $name) {
                return (string) ($option['value'] ?? '');
            }
        }

        return '';
    }

    /** @return array<string, mixed> */
    private function text(): array
    {
        return trans('overseer::overseer.discord');
    }
}
