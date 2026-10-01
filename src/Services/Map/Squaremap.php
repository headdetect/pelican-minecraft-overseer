<?php

namespace Headdetect\Overseer\Services\Map;

/**
 * Reads squaremap's config and the JSON files its web server publishes.
 * Pure functions, so they can be tested without a panel.
 *
 * squaremap serves the map at http://<host>:<port>/ with
 *   tiles/settings.json            the list of worlds
 *   tiles/<world>/settings.json    zoom levels and spawn for one world
 *   tiles/<world>/<z>/<x>_<y>.png  512px tiles; at zoom max one pixel is one block
 *   tiles/players.json             everyone online, with world and position
 */
final class Squaremap
{
    /** Where squaremap keeps its config on Paper, then on Fabric and NeoForge. */
    public const CONFIG_PATHS = ['plugins/squaremap/config.yml', 'config/squaremap/config.yml'];

    public const DEFAULT_PORT = 8080;

    /**
     * Pulls the internal web server settings out of squaremap's config.yml.
     *
     * @return array{enabled: bool, port: int}
     */
    public static function parseConfig(string $yaml): array
    {
        $enabled = self::yamlValue($yaml, ['settings', 'internal-webserver', 'enabled']);
        $port = self::yamlValue($yaml, ['settings', 'internal-webserver', 'port']);

        return [
            'enabled' => $enabled === null || strtolower($enabled) !== 'false',
            'port' => $port !== null && ctype_digit($port) ? (int) $port : self::DEFAULT_PORT,
        ];
    }

    /**
     * The worlds in tiles/settings.json, in squaremap's order.
     *
     * @return array<int, array{name: string, label: string, type: string}>
     */
    public static function worlds(array $settings): array
    {
        $worlds = array_values(array_filter($settings['worlds'] ?? [], fn ($world) => is_array($world) && self::isWorldName($world['name'] ?? null)));
        usort($worlds, fn ($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

        return array_map(fn (array $world) => [
            'name' => $world['name'],
            'label' => strip_tags((string) ($world['display_name'] ?? $world['name'])),
            'type' => match ($world['type'] ?? null) {
                'nether' => 'nether',
                'the_end' => 'end',
                'normal' => 'overworld',
                default => 'custom',
            },
        ], $worlds);
    }

    /**
     * Zoom levels and spawn from tiles/<world>/settings.json.
     *
     * @return array{max: int, def: int, extra: int, spawn: array{x: int, z: int}}
     */
    public static function worldSettings(array $settings): array
    {
        $int = fn ($value, int $default) => is_numeric($value) ? (int) $value : $default;

        return [
            'max' => max(0, $int($settings['zoom']['max'] ?? null, 3)),
            'def' => max(0, $int($settings['zoom']['def'] ?? null, 0)),
            'extra' => max(0, $int($settings['zoom']['extra'] ?? null, 2)),
            'spawn' => [
                'x' => $int($settings['spawn']['x'] ?? null, 0),
                'z' => $int($settings['spawn']['z'] ?? null, 0),
            ],
        ];
    }

    /**
     * Players from tiles/players.json. Players whose position squaremap hides are left out.
     *
     * @return array<int, array{name: string, world: string, x: int, y: ?int, z: int, yaw: ?int, health: ?int}>
     */
    public static function players(array $json): array
    {
        $players = [];
        foreach ($json['players'] ?? [] as $player) {
            if (!is_array($player) || !is_string($player['name'] ?? null) || !isset($player['x'], $player['z']) || !self::isWorldName($player['world'] ?? null)) {
                continue;
            }

            $players[] = [
                'name' => $player['name'],
                'world' => $player['world'],
                'x' => (int) $player['x'],
                'y' => isset($player['y']) ? (int) $player['y'] : null,
                'z' => (int) $player['z'],
                'yaw' => isset($player['yaw']) ? (int) $player['yaw'] : null,
                'health' => isset($player['health']) ? (int) $player['health'] : null,
            ];
        }

        return $players;
    }

    /** Only these paths are passed through to squaremap: world settings and tile images. */
    public static function isProxiedPath(string $path): bool
    {
        return (bool) preg_match('#^tiles/[A-Za-z0-9_.-]+/(settings\.json|-?\d{1,2}/-?\d{1,7}_-?\d{1,7}\.png)$#', $path)
            && !str_contains($path, '..');
    }

    public static function isWorldName(mixed $name): bool
    {
        return is_string($name) && preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $name) && !str_contains($name, '..');
    }

    /**
     * Reads one scalar from a YAML file by following nested mapping keys.
     * Enough for squaremap's config without pulling in a YAML library.
     *
     * @param  string[]  $path
     */
    private static function yamlValue(string $yaml, array $path): ?string
    {
        $depth = 0;
        $indents = [];

        foreach (preg_split('/\r\n|\r|\n/', $yaml) as $line) {
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }

            if (!preg_match('/^( *)["\']?([^"\':#]+?)["\']?\s*:(?:\s+(.*?))?\s*$/', $line, $m)) {
                continue;
            }

            $indent = strlen($m[1]);

            // Leaving a block we had entered means the key isn't there.
            while ($depth > 0 && $indent <= $indents[$depth - 1]) {
                $depth--;
                if ($depth < count($path) - 1) {
                    return null;
                }
            }

            if ($m[2] !== $path[$depth]) {
                continue;
            }

            if ($depth === count($path) - 1) {
                $value = preg_replace('/\s+#.*$/', '', $m[3] ?? '');

                return trim($value, " \"'");
            }

            $indents[$depth] = $indent;
            $depth++;
        }

        return null;
    }
}
