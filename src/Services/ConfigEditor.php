<?php

namespace Headdetect\Overseer\Services;

use App\Models\Server;
use Exception;
use Headdetect\Overseer\Support\ConfigSchema;
use InvalidArgumentException;

/**
 * What the Config page shows and saves: every source's settings with their
 * current values, and the checks that turn form values into file changes.
 * The browser runs the same checks to show errors as the admin types. The
 * server runs them again here before it writes anything.
 */
class ConfigEditor
{
    /** Keys that change how Overseer reaches RCON. */
    public const RCON_KEYS = ['enable-rcon', 'rcon.port', 'rcon.password'];

    public function __construct(private readonly ConfigFiles $files) {}

    /**
     * Every source with its settings and current values.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sources(Server $server): array
    {
        $sources = [];

        foreach (array_keys(ConfigSchema::SOURCES) as $source) {
            try {
                $loaded = $this->files->load($server, $source);
            } catch (Exception $exception) {
                report($exception);
                $loaded = ['values' => [], 'advanced' => [], 'unavailable' => 'error'];
            }

            // Not a Paper server: no Paper tab.
            if ($source === 'paper' && $loaded['unavailable'] === 'missing') {
                continue;
            }

            $schema = ConfigSchema::source($source);
            $sources[] = [
                'key' => $source,
                'title' => $schema['title'],
                'icon' => $schema['icon'],
                'format' => $schema['format'],
                'unavailable' => $loaded['unavailable'],
                'entries' => array_map(self::forBrowser(...), self::entriesFor($source, $loaded['values'], $loaded['advanced'])),
                'values' => (object) $loaded['values'],
            ];
        }

        return $sources;
    }

    /**
     * The settings to show for a source: the curated ones the server has, then the rest.
     *
     * @param  array<string, bool|int|string>  $values
     * @param  string[]  $advanced
     * @return array<string, array<string, mixed>>
     */
    public static function entriesFor(string $source, array $values, array $advanced): array
    {
        $entries = array_diff_key(
            array_intersect_key(ConfigSchema::entries($source), $values),
            array_flip($advanced),
        );

        foreach ($entries as $key => $entry) {
            // Keep a value we don't know about selectable instead of silently changing it.
            $current = (string) $values[$key];
            if ($entry['type'] === 'enum' && $current !== '' && !array_key_exists($current, $entry['options'])) {
                $entries[$key]['options'][$current] = $current;
            }
        }

        foreach ($advanced as $key) {
            $entries[$key] = ConfigSchema::advancedEntry($key);
        }

        return $entries;
    }

    /**
     * Settings whose submitted value differs from the current one. A value that
     * fails its checks counts as changed and has the error message instead.
     *
     * @param  array<string, array<string, mixed>>  $entries
     * @param  array<string, bool|int|string>  $current  values as loaded from the file
     * @param  array<string, mixed>  $submitted  key => form value
     * @return array<string, array{entry: array<string, mixed>, old: ?string, new: ?string, error: ?string}>
     */
    public static function changes(array $entries, array $current, array $submitted): array
    {
        $changes = [];

        foreach ($submitted as $key => $value) {
            $entry = $entries[$key] ?? null;
            if ($entry === null) {
                continue;
            }

            if ($entry['type'] === 'password') {
                if (trim((string) $value) !== '') {
                    $error = match (true) {
                        (bool) preg_match('/[\x00-\x1F\x7F\s]/', (string) $value) => trans('overseer::overseer.config.password_spaces'),
                        mb_strlen((string) $value) > 100 => trans('overseer::overseer.config.password_long'),
                        default => null,
                    };
                    $changes[$key] = ['entry' => $entry, 'old' => null, 'new' => $error ? null : (string) $value, 'error' => $error];
                }

                continue;
            }

            // Untouched: don't re-check it, so a value outside our limits that was already in the file stays as it is.
            $old = ConfigSchema::plain($current[$key] ?? '');
            if (ConfigSchema::plain($value) === $old) {
                continue;
            }

            try {
                $new = ConfigSchema::toFile($entry, $value);
                $error = null;
            } catch (InvalidArgumentException $exception) {
                $new = null;
                $error = $exception->getMessage();
            }

            if ($new !== $old) {
                $changes[$key] = ['entry' => $entry, 'old' => $old, 'new' => $new, 'error' => $error];
            }
        }

        return $changes;
    }

    /**
     * Checks and saves submitted values, source by source.
     *
     * @param  array<string, array<string, mixed>>  $submitted  source => key => form value
     * @return array{saved: int, restart: bool, failed: array<string, string>, sources: string[]}
     *
     * @throws InvalidArgumentException when a value fails its checks, before anything is written
     */
    public function save(Server $server, array $submitted): array
    {
        $plan = [];

        foreach ($submitted as $source => $values) {
            if (!isset(ConfigSchema::SOURCES[$source]) || !is_array($values)) {
                continue;
            }

            $loaded = $this->files->load($server, $source);
            if ($loaded['unavailable']) {
                continue;
            }

            $changes = self::changes(self::entriesFor($source, $loaded['values'], $loaded['advanced']), $loaded['values'], $values);
            foreach ($changes as $change) {
                if ($change['error'] !== null) {
                    throw new InvalidArgumentException($change['error']);
                }
            }

            if ($changes !== []) {
                $plan[$source] = $changes;
            }
        }

        $result = ['saved' => 0, 'restart' => false, 'failed' => [], 'sources' => []];

        foreach ($plan as $source => $changes) {
            try {
                $result['restart'] = $this->files->save(
                    $server,
                    $source,
                    array_map(fn (array $change) => $change['new'], $changes),
                    array_map(fn (array $change) => $change['entry'], $changes),
                ) || $result['restart'];
                $result['saved'] += count($changes);
                $result['sources'][] = $source;
            } catch (Exception $exception) {
                report($exception);
                $result['failed'][ConfigSchema::source($source)['title']] = $exception->getMessage();
            }
        }

        return $result;
    }

    /** Leaves out what only the server needs, like the commands that apply a change live. */
    private static function forBrowser(array $entry): array
    {
        $entry = array_intersect_key($entry, array_flip([
            'title', 'help', 'type', 'options', 'min', 'max', 'unit', 'placeholder', 'default', 'group', 'restart', 'advanced',
        ]));

        // Pairs keep the order and the string values. JSON would turn keys 0..n into a list.
        if (isset($entry['options'])) {
            $entry['options'] = array_map(fn ($value, $label) => [(string) $value, $label], array_keys($entry['options']), $entry['options']);
        }

        return $entry;
    }
}
