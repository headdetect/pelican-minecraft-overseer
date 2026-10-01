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

    /**
     * Changes only the lines for the given keys and leaves every other line,
     * comment and blank line exactly as it was. Keys that aren't in the file yet
     * are added at the end.
     *
     * @param  array<string, string>  $changes
     */
    public static function update(string $contents, array $changes): string
    {
        $eol = str_contains($contents, "\r\n") ? "\r\n" : "\n";
        $lines = $contents === '' ? [] : preg_split('/\r\n|\r|\n/', $contents);

        // A trailing newline leaves an empty last element; put it back at the end.
        $trailing = $lines !== [] && end($lines) === '';
        if ($trailing) {
            array_pop($lines);
        }

        foreach ($lines as $i => $line) {
            $key = self::keyOf($line);
            if ($key !== null && array_key_exists($key, $changes)) {
                $lines[$i] = self::line($key, $changes[$key]);
                unset($changes[$key]);
            }
        }

        foreach ($changes as $key => $value) {
            $lines[] = self::line($key, $value);
        }

        return implode($eol, $lines) . ($trailing || $contents === '' ? $eol : '');
    }

    private static function keyOf(string $line): ?string
    {
        $line = ltrim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === '!') {
            return null;
        }

        return preg_match('/^((?:\\\\.|[^=:\s\\\\])+)/', $line, $match) ? self::unescape($match[1]) : null;
    }

    private static function line(string $key, string $value): string
    {
        return self::escape($key, true) . '=' . self::escape($value, false);
    }

    /** Escapes the way Java's Properties.store() does, which is how Minecraft writes the file. */
    private static function escape(string $text, bool $isKey): string
    {
        $out = '';
        $chars = mb_str_split($text, 1, 'UTF-8');

        foreach ($chars as $i => $c) {
            $out .= match (true) {
                $c === '\\' => '\\\\',
                $c === "\t" => '\\t',
                $c === "\n" => '\\n',
                $c === "\r" => '\\r',
                $c === "\f" => '\\f',
                $c === ' ' && ($isKey || $i === 0) => '\\ ',
                in_array($c, ['=', ':', '#', '!'], true) => '\\' . $c,
                default => $c,
            };
        }

        return $out;
    }
}
