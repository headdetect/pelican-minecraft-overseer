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
    /**
     * An item id such as "diamond" or "minecraft:diamond_sword", or a modded one
     * like "create:wrench". No NBT or components, so nothing else can follow it.
     */
    public static function itemId(string $item): string
    {
        $item = strtolower(trim($item));

        if (!preg_match('/^(?:[a-z0-9_.-]+:)?[a-z0-9_.\/-]{1,100}$/', $item)) {
            throw new InvalidArgumentException("\"$item\" is not an item id, like minecraft:diamond.");
        }

        return $item;
    }

    public const GAME_MODES = ['survival', 'creative', 'adventure', 'spectator'];

    public static function gameMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, self::GAME_MODES, true)) {
            throw new InvalidArgumentException("\"$mode\" isn't a game mode.");
        }

        return $mode;
    }

    public const DIMENSIONS = ['minecraft:overworld', 'minecraft:the_nether', 'minecraft:the_end'];

    /**
     * "tp Doobie kelp_lord" to send a player to another player, or
     * "execute in minecraft:the_nether run tp Doobie 10 64 -20" for coordinates.
     *
     * @param  array{to?: string, target?: string, x?: mixed, y?: mixed, z?: mixed, dimension?: string}  $data
     */
    public static function teleport(string $player, array $data): string
    {
        $player = self::playerName($player);

        if (($data['to'] ?? 'player') === 'player') {
            return sprintf('tp %s %s', $player, self::playerName((string) ($data['target'] ?? '')));
        }

        $dimension = (string) ($data['dimension'] ?? self::DIMENSIONS[0]);
        if (!in_array($dimension, self::DIMENSIONS, true)) {
            throw new InvalidArgumentException("\"$dimension\" isn't a dimension Overseer offers.");
        }

        foreach (['x', 'y', 'z'] as $axis) {
            if (!is_numeric($data[$axis] ?? null)) {
                throw new InvalidArgumentException(strtoupper($axis) . ' must be a number.');
            }
        }

        return sprintf('execute in %s run tp %s %d %d %d', $dimension, $player, (int) $data['x'], (int) $data['y'], (int) $data['z']);
    }

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
    /**
     * Minecraft joins the lines of an RCON reply with nothing between them:
     * "Saving the game (this may take a moment!)Saved the game". Puts a space
     * where a sentence ends right against the next one. For display only.
     */
    public static function readableReply(string $text): string
    {
        return preg_replace('/([.!?)])(?=[A-Z])/', '$1 ', $text) ?? $text;
    }

    public static function stripFormatting(string $text): string
    {
        return preg_replace('/§./u', '', $text) ?? $text;
    }
}
