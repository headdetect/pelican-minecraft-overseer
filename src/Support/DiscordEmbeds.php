<?php

namespace Headdetect\Overseer\Support;

/**
 * The messages the Discord commands reply with. Pure, so they can be tested
 * without a panel: the text comes in from overseer::overseer.discord.
 */
final class DiscordEmbeds
{
    private const GREEN = 0x10B981;

    private const RED = 0xEF4444;

    private const GRAY = 0x6B7280;

    /** Discord messages from the bot never ping anyone, whatever a name or the info text says. */
    private const NO_MENTIONS = ['parse' => []];

    /** A plain text reply. */
    public static function text(string $content): array
    {
        return ['content' => $content, 'allowed_mentions' => self::NO_MENTIONS];
    }

    /**
     * /info: how to get on the whitelist and connect, whether the server is up,
     * who is on, and which modpack to download.
     *
     * @param  ?array{name: string, version: ?string, url: ?string, download_url?: ?string}  $modpack
     * @param  ?string  $uptime  "3h 4m", or null when the server is down or Wings didn't say
     * @param  ?string  $list  the reply to "list", or null when the server is down or RCON couldn't answer
     * @param  string[]  $names  who's online, from PlayerService::parseList
     * @param  array<string, mixed>  $text
     */
    public static function info(string $server, string $address, ?string $version, ?array $modpack, ?string $info, bool $running, ?string $uptime, ?string $list, array $names, array $text): array
    {
        $status = $running
            ? $text['embed']['online'] . ($uptime !== null ? ', ' . strtr($text['embed']['up_for'], [':time' => $uptime]) : '')
            : $text['embed']['offline'];

        $fields = [
            self::field($text['embed']['whitelist'], $text['embed']['whitelist_help']),
            self::field($text['embed']['address'], "```\n$address\n```"),
            self::field($text['embed']['status'], $status, true),
            self::field($text['embed']['version'], $version ? "$version, {$text['embed']['edition']}" : $text['embed']['edition'], true),
        ];

        if ($running && $list !== null) {
            $count = self::playerCount($list);
            $fields[] = self::field(
                $count ? "{$text['embed']['players']} ({$count[0]}/{$count[1]})" : $text['embed']['players'],
                $names ? self::escape(implode(', ', $names)) : $text['embed']['nobody'],
            );
        }

        if ($modpack) {
            $name = self::escape(trim($modpack['name'] . ' ' . ($modpack['version'] ?? '')));
            $value = $modpack['url'] ? "[$name]({$modpack['url']})" : $name;

            if (!empty($modpack['download_url'])) {
                $value .= "\n[{$text['embed']['download']}]({$modpack['download_url']})";
            }

            $fields[] = self::field($text['embed']['modpack'], $value);
        }

        return self::embed(array_filter([
            'title' => $server,
            'description' => trim((string) $info) !== '' ? mb_substr(trim($info), 0, 4000) : null,
            'color' => $running ? self::GREEN : self::RED,
            'fields' => $fields,
        ]));
    }

    /**
     * /whitelist went through.
     *
     * @param  array{id: string, name: string}  $profile
     * @param  ?string  $reply  the server's reply, or null when the command went through Wings
     * @param  array<string, mixed>  $text
     */
    public static function whitelisted(array $profile, ?string $reply, array $text): array
    {
        $key = match (true) {
            $reply === null => 'sent',
            str_contains(strtolower($reply), 'already') => 'already',
            default => 'added',
        };

        return self::embed([
            'title' => strtr($text['reply'][$key], [':name' => $profile['name']]),
            'color' => $key === 'already' ? self::GRAY : self::GREEN,
        ]);
    }

    /**
     * [online, max] from vanilla's "2 of a max of 20" or Paper's "2/20".
     *
     * @return ?array{0: int, 1: int}
     */
    public static function playerCount(string $list): ?array
    {
        return preg_match('/(\d+)\s*(?:of a max of|\/)\s*(\d+)/i', $list, $match) ? [(int) $match[1], (int) $match[2]] : null;
    }

    /** Java names can have underscores, which Discord would read as italics. */
    public static function escape(string $text): string
    {
        return preg_replace('/([\\\\_*~`|>])/', '\\\\$1', $text);
    }

    private static function embed(array $embed): array
    {
        return ['embeds' => [$embed], 'allowed_mentions' => self::NO_MENTIONS];
    }

    private static function field(string $name, string $value, bool $inline = false): array
    {
        return ['name' => $name, 'value' => mb_substr($value, 0, 1024), 'inline' => $inline];
    }
}
