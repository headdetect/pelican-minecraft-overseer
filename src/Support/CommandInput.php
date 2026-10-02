<?php

namespace Headdetect\Overseer\Support;

use InvalidArgumentException;

/**
 * Everything typed into the panel ends up inside a console command, so it is
 * checked here first. A name or reason must never be able to start a second
 * command or inject formatting.
 */
final class CommandInput
{
    /** Java names are 3-16 of A-Z, 0-9 and _. Bedrock players behind Floodgate get a "." or "*" prefix. */
    private const PLAYER_NAME = '/^[.*]?[A-Za-z0-9_]{1,16}$/';

    public static function playerName(string $name): string
    {
        $name = trim($name);

        if (!preg_match(self::PLAYER_NAME, $name)) {
            throw new InvalidArgumentException("\"$name\" is not a valid player name.");
        }

        return $name;
    }

    /** Free text such as a kick reason or broadcast: one line, no § formatting codes, bounded length. */
    public static function text(?string $text, int $max = 200): string
    {
        $text = (string) $text;
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '';
        $text = preg_replace('/§./u', '', $text) ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return mb_substr($text, 0, $max);
    }

    /** A raw console command typed by an admin: one line, no leading slash. */
    public static function command(string $command): string
    {
        $command = self::text($command, 1000);

        return ltrim($command, '/');
    }

    /** Strips § colour codes from server output. */
    public static function stripFormatting(string $text): string
    {
        return preg_replace('/§./u', '', $text) ?? $text;
    }
}
