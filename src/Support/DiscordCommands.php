<?php

namespace Headdetect\Overseer\Support;

/**
 * The slash commands the panel registers in a Discord guild, in the shape
 * Discord's application command API takes. Pure, so it can be tested
 * without a panel.
 */
final class DiscordCommands
{
    private const CHAT_INPUT = 1;

    private const STRING = 3;

    /** Only inside a guild. Never in DMs, so only members of the linked Discord can run them. */
    private const GUILD = 0;

    /** Installed to a guild, rather than to a user's account. */
    private const GUILD_INSTALL = 0;

    /**
     * @param  array<string, string>  $text  descriptions, from overseer::overseer.discord.commands
     * @return array<int, array<string, mixed>>
     */
    public static function definitions(array $text): array
    {
        $command = fn (string $name, array $options = []) => array_filter([
            'name' => $name,
            'type' => self::CHAT_INPUT,
            'description' => $text[$name],
            'options' => $options,
            'contexts' => [self::GUILD],
            'integration_types' => [self::GUILD_INSTALL],
        ]);

        return [
            $command('info'),
            $command('whitelist', [[
                'type' => self::STRING,
                'name' => 'name',
                'description' => $text['whitelist_name'],
                'required' => true,
                'min_length' => 1,
                // Longer than any Java name, so a gamertag reaches the Bedrock check
                // instead of Discord turning it away with its own error.
                'max_length' => 32,
            ]]),
        ];
    }
}
