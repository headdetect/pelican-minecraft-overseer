<?php

namespace Headdetect\Underseer\Support;

/**
 * Reads Java .properties files such as server.properties.
 * Handles comments, "key=value" and "key:value", and the usual backslash escapes.
 */
final class Properties
{
    /** @return array<string, string> */
    public static function parse(string $contents): array
    {
        $values = [];

        foreach (preg_split('/\r\n|\r|\n/', $contents) as $line) {
            $line = ltrim($line);
            if ($line === '' || $line[0] === '#' || $line[0] === '!') {
                continue;
            }

            if (!preg_match('/^((?:\\\\.|[^=:\s\\\\])+)\s*[=:]?\s*(.*)$/', $line, $match)) {
                continue;
            }

            $values[self::unescape($match[1])] = self::unescape($match[2]);
        }

        return $values;
    }

    private static function unescape(string $value): string
    {
        return preg_replace_callback('/\\\\(u[0-9a-fA-F]{4}|.)/', function (array $m) {
            $c = $m[1];

            return match (true) {
                $c[0] === 'u' && strlen($c) === 5 => mb_chr(hexdec(substr($c, 1)), 'UTF-8'),
                $c === 't' => "\t",
                $c === 'n' => "\n",
                $c === 'r' => "\r",
                $c === 'f' => "\f",
                default => $c,
            };
        }, $value) ?? $value;
    }

    public static function bool(array $values, string $key, bool $default = false): bool
    {
        return isset($values[$key]) ? strtolower($values[$key]) === 'true' : $default;
    }
}
