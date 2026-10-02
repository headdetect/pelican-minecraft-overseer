<?php

namespace Headdetect\Overseer\Support;

use InvalidArgumentException;

/**
 * The settings the Config page knows about, loaded from resources/schemas/,
 * and the rules for turning file text into form values and back.
 */
final class ConfigSchema
{
    /** Source key => schema file, in the order the page lists them. */
    public const SOURCES = [
        'server' => 'server-properties.php',
        'rules' => 'gamerules.php',
        'paper' => 'paper-world.php',
    ];

    /** @var array<string, array<string, mixed>> */
    private static array $loaded = [];

    /** @return array<string, mixed> */
    public static function source(string $source): array
    {
        if (!isset(self::SOURCES[$source])) {
            throw new InvalidArgumentException("Unknown config source \"$source\".");
        }

        return self::$loaded[$source] ??= require dirname(__DIR__, 2) . '/resources/schemas/' . self::SOURCES[$source];
    }

    /**
     * Every curated setting of a source, with its group added.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function entries(string $source): array
    {
        $entries = [];
        foreach (self::source($source)['groups'] as $group => $settings) {
            foreach ($settings as $key => $entry) {
                $entries[$key] = $entry + ['group' => $group, 'restart' => false];
            }
        }

        return $entries;
    }

    /** A setting we have no description for, shown under Advanced as a plain text box. */
    public static function advancedEntry(string $key): array
    {
        return [
            'title' => $key,
            'help' => 'No description yet. This is the raw value from the file.',
            'type' => 'string',
            'group' => 'Advanced',
            'restart' => true,
            'advanced' => true,
        ];
    }

    /** Whether a key may appear in the editor at all. */
    public static function isHidden(string $source, string $key): bool
    {
        return in_array($key, self::source($source)['hidden'] ?? [], true);
    }

    /**
     * Turns the text from a file into the value the form control holds.
     * Returns null when the file holds something the control can't show, so the
     * setting is left out instead of being overwritten by mistake.
     */
    public static function fromFile(array $entry, string $raw): bool|int|string|null
    {
        $raw = trim($raw);

        return match ($entry['type']) {
            'bool' => in_array(strtolower($raw), ['true', 'false'], true) ? strtolower($raw) === 'true' : null,
            'int', 'range' => preg_match('/^-?\d+$/', $raw) ? (int) $raw : null,
            'password' => '',
            default => $raw,
        };
    }

    /**
     * Checks a value from the form and turns it into the text written to the file.
     *
     * @throws InvalidArgumentException with a message an admin can act on
     */
    /** Whether a form value equals the entry's default. Lenient about types, since forms send numbers as text. */
    public static function isDefault(array $entry, mixed $value): bool
    {
        if (!array_key_exists('default', $entry)) {
            return true;
        }

        $default = $entry['default'];

        return match ($entry['type']) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === $default,
            'int', 'range' => is_numeric($value) && (int) $value == $value && (int) $value === (int) $default,
            default => trim((string) $value) === trim((string) $default),
        };
    }

    public static function toFile(array $entry, mixed $value): string
    {
        $title = $entry['title'];

        switch ($entry['type']) {
            case 'bool':
                return filter_var($value, FILTER_VALIDATE_BOOL) ? 'true' : 'false';

            case 'int':
            case 'range':
                if (is_string($value)) {
                    $value = trim($value);
                }
                if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/', $value))) {
                    throw new InvalidArgumentException("$title must be a whole number.");
                }
                $value = (int) $value;
                if (isset($entry['min']) && $value < $entry['min']) {
                    throw new InvalidArgumentException("$title can't be less than {$entry['min']}.");
                }
                if (isset($entry['max']) && $value > $entry['max']) {
                    throw new InvalidArgumentException("$title can't be more than {$entry['max']}.");
                }

                return (string) $value;

            case 'enum':
                $value = (string) $value;
                if (!array_key_exists($value, $entry['options'])) {
                    throw new InvalidArgumentException("\"$value\" is not a choice for $title.");
                }

                return $value;

            default:
                // One line of text with no control characters: a newline would start a new setting.
                $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value) ?? '';
                $max = $entry['max'] ?? 1000;
                if (mb_strlen($text) > $max) {
                    throw new InvalidArgumentException("$title can be at most $max characters.");
                }

                return trim($text);
        }
    }

    /** A form or file value as plain text, for comparing without checking it. */
    public static function plain(mixed $value): string
    {
        return is_bool($value) ? ($value ? 'true' : 'false') : trim((string) $value);
    }

    /** A form field name for a key. Keys contain dots, which form paths treat as nesting. */
    public static function fieldName(string $key): string
    {
        return 'f' . substr(md5($key), 0, 12);
    }

    /** Whether a setting matches what was typed in the search box. */
    public static function matches(string $key, array $entry, ?string $search): bool
    {
        $search = trim((string) $search);
        if ($search === '') {
            return true;
        }

        return str_contains(mb_strtolower($entry['title'] . ' ' . $entry['help'] . ' ' . $key), mb_strtolower($search));
    }

    /**
     * The console command that applies a change right away, or null when it needs a restart.
     */
    public static function liveCommand(array $entry, string $fileValue): ?string
    {
        $live = $entry['live'] ?? null;

        if (is_array($live)) {
            return $live[$fileValue] ?? null;
        }

        return is_string($live) ? str_replace('{value}', $fileValue, $live) : null;
    }
}
