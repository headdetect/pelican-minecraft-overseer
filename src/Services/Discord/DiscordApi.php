<?php

namespace Headdetect\Overseer\Services\Discord;

use Headdetect\Overseer\Support\DiscordCommands;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * The few Discord REST calls Overseer makes, with the bot token from the
 * plugin settings. Every call throws a RequestException when Discord says no.
 */
class DiscordApi
{
    private const BASE = 'https://discord.com/api/v10';

    public static function configured(): bool
    {
        return filled(config('overseer.discord.app_id'))
            && filled(config('overseer.discord.public_key'))
            && filled(config('overseer.discord.bot_token'));
    }

    /** The link that adds the bot to a guild. The bot asks for no permissions, since it only answers commands. */
    public static function inviteUrl(): string
    {
        return 'https://discord.com/oauth2/authorize?' . http_build_query([
            'client_id' => config('overseer.discord.app_id'),
            'scope' => 'bot applications.commands',
            'permissions' => 0,
        ]);
    }

    /**
     * The guilds the bot is in.
     *
     * @return array<string, string> guild id => name
     *
     * @throws RequestException
     */
    public function guilds(): array
    {
        $guilds = $this->bot()->get('/users/@me/guilds')->throw()->json();

        return collect($guilds)->mapWithKeys(fn (array $guild) => [(string) $guild['id'] => (string) $guild['name']])->all();
    }

    /**
     * Replaces the guild's commands with /status, /info and /whitelist.
     * Guild commands show up in Discord right away.
     *
     * @throws RequestException
     */
    public function registerCommands(string $guildId): void
    {
        $this->bot()
            ->put('/applications/' . config('overseer.discord.app_id') . "/guilds/$guildId/commands", DiscordCommands::definitions(trans('overseer::overseer.discord.commands')))
            ->throw();
    }

    /**
     * Replaces the "thinking" reply to an interaction. The interaction token
     * allows this for 15 minutes, without the bot token.
     *
     * @param  array<string, mixed>  $message
     *
     * @throws RequestException
     */
    public function editReply(string $interactionToken, array $message): void
    {
        Http::baseUrl(self::BASE)
            ->timeout((float) config('overseer.discord.timeout', 2.0))
            ->patch('/webhooks/' . config('overseer.discord.app_id') . "/$interactionToken/messages/@original", $message)
            ->throw();
    }

    private function bot(): PendingRequest
    {
        return Http::baseUrl(self::BASE)
            ->withHeaders(['Authorization' => 'Bot ' . config('overseer.discord.bot_token')])
            ->timeout((float) config('overseer.discord.timeout', 2.0))
            ->acceptJson();
    }
}
