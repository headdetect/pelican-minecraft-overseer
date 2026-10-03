<?php

namespace Headdetect\Overseer\Support;

/**
 * Reads and changes single values in a YAML file such as Paper's config, by
 * dotted path ("anticheat.anti-xray.enabled"), one line at a time.
 *
 * A YAML library would drop every comment when it writes the file back, and
 * Paper's files are mostly comments. This only understands plain block
 * mappings with "key: value" lines, which is all Paper writes for the
 * settings we expose. Anything else is reported as missing and left alone.
 */
final class YamlLines
{
    /** The value at a path, or null when the path isn't a plain value in the file. */
    public static function get(string $contents, string $path): ?string
    {
        $lines = preg_split('/\r\n|\r|\n/', $contents);
        $index = self::find($lines, $path);

        return $index === null ? null : self::parseLine($lines[$index])['value'];
    }

    /**
     * Replaces the value at a path, keeping the key, indentation and any comment
     * on the same line. Returns null when the path isn't a plain value in the file.
     */
    public static function set(string $contents, string $path, string $value): ?string
    {
        $eol = str_contains($contents, "\r\n") ? "\r\n" : "\n";
        $lines = preg_split('/\r\n|\r|\n/', $contents);
        $index = self::find($lines, $path);
        if ($index === null) {
            return null;
        }

        $line = self::parseLine($lines[$index]);
        $lines[$index] = $line['prefix'] . self::scalar($value) . $line['comment'];

        return implode($eol, $lines);
    }

    /** Formats a value so YAML reads it back as the same string, number or boolean. */
    public static function scalar(string $value): string
    {
        // YAML 1.1 (SnakeYAML) reads these words as booleans or null, so quote them to keep a string.
        // true and false stay plain: boolean settings write them on purpose.
        $words = ['yes', 'no', 'on', 'off', 'y', 'n', 'null', '~'];
        if ($value === '' || in_array(strtolower($value), $words, true) || preg_match('/^[\s\'"&*!|>%@`{}\[\],#?:-]|[\s:]$|: | #/', $value) || preg_match('/[\x00-\x1F]/', $value)) {
            return "'" . str_replace("'", "''", $value) . "'";
        }

        return $value;
    }

    /** @param  string[]  $lines */
    private static function find(array $lines, string $path): ?int
    {
        $start = 0;
        $end = count($lines);
        $parentIndent = -1;
        $segments = explode('.', $path);

        foreach ($segments as $depth => $segment) {
            $found = null;

            // The first key line under the parent sets the indentation of its children.
            $childIndent = null;
            for ($i = $start; $i < $end; $i++) {
                if (self::isBlank($lines[$i])) {
                    continue;
                }
                $indent = strlen($lines[$i]) - strlen(ltrim($lines[$i], ' '));
                if ($indent <= $parentIndent) {
                    break;
                }
                $childIndent ??= $indent;
                if ($indent !== $childIndent) {
                    continue;
                }
                if (self::parseLine($lines[$i])['key'] === $segment) {
                    $found = $i;
                    break;
                }
            }

            if ($found === null || $childIndent === null) {
                return null;
            }

            $isLast = $depth === count($segments) - 1;
            $value = self::parseLine($lines[$found])['value'];

            if ($isLast) {
                // A key with nothing after the colon is a nested mapping or a list, not a value.
                return $value === '' ? null : $found;
            }

            if ($value !== '') {
                return null;
            }

            $parentIndent = $childIndent;
            $start = $found + 1;
            for ($i = $start; $i < $end; $i++) {
                if (self::isBlank($lines[$i])) {
                    continue;
                }
                if (strlen($lines[$i]) - strlen(ltrim($lines[$i], ' ')) <= $parentIndent) {
                    $end = $i;
                    break;
                }
            }
        }

        return null;
    }

    private static function isBlank(string $line): bool
    {
        $trimmed = trim($line);

        return $trimmed === '' || $trimmed[0] === '#';
    }

    /** @return array{key: ?string, value: string, prefix: string, comment: string} */
    private static function parseLine(string $line): array
    {
        $none = ['key' => null, 'value' => '', 'prefix' => '', 'comment' => ''];

        // key, optional quotes around it, then ":" and the rest of the line
        if (!preg_match('/^(\s*)(?:\'([^\']*)\'|"([^"]*)"|([^\s#\'"][^:#]*?))\s*:(?:\s+|$)(.*)$/', $line, $m)) {
            return $none;
        }

        $key = $m[2] !== '' ? $m[2] : ($m[3] !== '' ? $m[3] : $m[4]);
        $rest = $m[5];
        $prefix = substr($line, 0, strlen($line) - strlen($rest));

        [$raw, $comment] = self::splitComment($rest);
        $value = trim($raw);

        // Keep the spacing before a comment as part of the comment so it survives a rewrite.
        $comment = substr($rest, strlen(rtrim($raw)));

        if (strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) {
            $value = str_replace("''", "'", substr($value, 1, -1));
        } elseif (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            $value = stripcslashes(substr($value, 1, -1));
        } elseif ($value !== '' && in_array($value[0], ['[', '{', '|', '>', '&', '*', '!'], true)) {
            // Inline lists, maps, block scalars, anchors and tags aren't plain values.
            return ['key' => $key, 'value' => '', 'prefix' => $prefix, 'comment' => ''];
        }

        return ['key' => $key, 'value' => $value, 'prefix' => $prefix, 'comment' => $comment];
    }

    /** @return array{0: string, 1: string} the value part and the comment part (with its "#") */
    private static function splitComment(string $rest): array
    {
        $quote = null;
        $length = strlen($rest);

        for ($i = 0; $i < $length; $i++) {
            $c = $rest[$i];
            if ($quote !== null) {
                if ($c === $quote) {
                    $quote = null;
                }
            } elseif ($c === "'" || $c === '"') {
                $quote = $c;
            } elseif ($c === '#' && ($i === 0 || ctype_space($rest[$i - 1]))) {
                return [substr($rest, 0, $i), substr($rest, $i)];
            }
        }

        return [$rest, ''];
    }
}
