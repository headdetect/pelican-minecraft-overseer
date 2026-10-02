<?php

namespace Headdetect\Overseer\Support;

/**
 * Finds chat in the server's console lines. Pure functions, so they can be
 * tested without a panel.
 *
 * Vanilla, Fabric and NeoForge log chat on the server thread, and Paper on an
 * async chat thread. Both look like:
 *   [12:04:31] [Server thread/INFO]: <Doobie> hi all
 *   [12:04:31] [Async Chat Thread - #0/INFO]: [Not Secure] <Doobie> hi all
 *   [12:05:02] [Server thread/INFO]: [Rcon] restarting in 5
 *   [12:06:10] [Server thread/INFO]: Doobie joined the game
 */
final class ChatLog
{
    private const NAME = '[.*]?[A-Za-z0-9_]{1,16}';

    /** Mods and plugins that log status lines as "[Name] text", which look like /say. */
    private const NOT_SAY = ['Chunky', 'squaremap', 'LuckPerms', 'WorldEdit', 'Essentials'];

    /**
     * @param  string[]  $lines  console lines, oldest first
     * @return array<int, array{time: string, type: string, name: string, text: string}> oldest first
     */
    public static function parse(array $lines, int $limit = 50): array
    {
        $messages = [];

        foreach ($lines as $line) {
            $line = CommandInput::stripFormatting(rtrim((string) $line));
            if (!preg_match('/^\[(\d{2}:\d{2}:\d{2})\] \[[^\]]+\/INFO\]: (?:\[Not Secure\] )?(.+)$/', $line, $m)) {
                continue;
            }

            [, $time, $body] = $m;
            $name = self::NAME;

            $message = match (true) {
                (bool) preg_match("/^<($name)> (.*)$/", $body, $c) => ['type' => 'chat', 'name' => $c[1], 'text' => $c[2]],
                (bool) preg_match("/^\\[($name)\\] (.*)$/", $body, $c) && !in_array($c[1], self::NOT_SAY, true) => ['type' => 'say', 'name' => $c[1], 'text' => $c[2]],
                (bool) preg_match("/^($name) joined the game$/", $body, $c) => ['type' => 'join', 'name' => $c[1], 'text' => ''],
                (bool) preg_match("/^($name) left the game$/", $body, $c) => ['type' => 'leave', 'name' => $c[1], 'text' => ''],
                default => null,
            };

            if ($message) {
                $messages[] = ['time' => $time, ...$message];
            }
        }

        return array_slice($messages, -$limit);
    }
}
