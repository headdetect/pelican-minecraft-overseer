<?php

namespace Headdetect\Underseer\Services;

use App\Enums\ContainerStatus;
use App\Facades\Activity;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use Headdetect\Underseer\Support\ConfigSchema;
use Headdetect\Underseer\Support\Properties;
use Headdetect\Underseer\Support\YamlLines;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use RuntimeException;

/**
 * Reads and saves the settings the Config page edits. Files are changed line by
 * line through Wings, so comments, order and settings we don't know about stay
 * as they were, and a copy of the old file is kept first.
 */
class ConfigFiles
{
    public const BACKUP_DIR = '.underseer/backups';

    private const MAX_FILE_SIZE = 1024 * 1024;

    public function __construct(
        private readonly ConsoleService $console,
        private readonly GameRules $rules,
    ) {}

    /**
     * Current values for a source.
     *
     * "unavailable" is "missing", "offline" or "no_rcon" when the source can't be shown.
     *
     * @return array{values: array<string, bool|int|string>, advanced: string[], unavailable: ?string}
     */
    public function load(Server $server, string $source): array
    {
        $schema = ConfigSchema::source($source);
        $entries = ConfigSchema::entries($source);
        $result = ['values' => [], 'advanced' => [], 'unavailable' => null];

        if ($schema['format'] === 'gamerules') {
            if ($server->retrieveStatus() !== ContainerStatus::Running) {
                return ['unavailable' => 'offline'] + $result;
            }
            if (!$this->console->hasRcon($server)) {
                return ['unavailable' => 'no_rcon'] + $result;
            }

            foreach ($entries as $key => $entry) {
                $raw = $this->rules->read($server, array_key_exists('new', $entry) ? $entry['new'] : $key, $entry['old']);
                $value = $raw === null ? null : ConfigSchema::fromFile($entry, $raw);
                if ($value !== null) {
                    $result['values'][$key] = $value;
                }
            }

            return $result;
        }

        $contents = $this->read($server, $schema['file']);
        if ($contents === null) {
            return ['unavailable' => 'missing'] + $result;
        }

        if ($schema['format'] === 'properties') {
            $properties = Properties::parse($contents);

            foreach ($properties as $key => $raw) {
                if (ConfigSchema::isHidden($source, $key)) {
                    continue;
                }

                if (isset($entries[$key])) {
                    $value = ConfigSchema::fromFile($entries[$key], $raw);
                    if ($value !== null) {
                        $result['values'][$key] = $value;
                    }
                } else {
                    $result['values'][$key] = $raw;
                    $result['advanced'][] = $key;
                }
            }

            return $result;
        }

        foreach ($entries as $key => $entry) {
            $raw = YamlLines::get($contents, $key);
            $value = $raw === null ? null : ConfigSchema::fromFile($entry, $raw);
            if ($value !== null) {
                $result['values'][$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Saves changed settings. Values must already have been through ConfigSchema::toFile().
     *
     * @param  array<string, string>  $changes  key => new file value
     * @param  array<string, array<string, mixed>>  $entries  the schema entry of every changed key
     * @return bool whether a restart is needed for the changes to apply
     */
    public function save(Server $server, string $source, array $changes, array $entries): bool
    {
        if ($changes === []) {
            return false;
        }

        $schema = ConfigSchema::source($source);
        $running = $server->retrieveStatus() === ContainerStatus::Running;

        if ($schema['format'] === 'gamerules') {
            foreach ($changes as $key => $value) {
                $entry = $entries[$key];
                $this->rules->write($server, array_key_exists('new', $entry) ? $entry['new'] : $key, $entry['old'], $value);
            }

            return false;
        }

        $file = $schema['file'];
        $contents = $this->read($server, $file);
        if ($contents === null) {
            throw new RuntimeException("$file no longer exists on the server.");
        }

        if ($schema['format'] === 'properties') {
            $updated = Properties::update($contents, $changes);
        } else {
            $updated = $contents;
            foreach ($changes as $key => $value) {
                $updated = YamlLines::set($updated, $key, $value)
                    ?? throw new RuntimeException("Couldn't find $key in $file. It may have been changed by hand; reload the page.");
            }
        }

        $restart = false;
        $commands = [];
        foreach ($changes as $key => $value) {
            $command = ConfigSchema::liveCommand($entries[$key], $value);
            if ($command !== null) {
                $commands[$key] = $command;
            } elseif ($entries[$key]['restart'] ?? true) {
                $restart = true;
            }
        }

        // Live commands go first. Some of them make the server write server.properties
        // from memory, which would undo the file change if it came before.
        if ($running) {
            foreach ($commands as $key => $command) {
                $this->console->run($server, 'setting', $command, $key);
            }
        }

        $backup = $this->backup($server, $file, $contents);
        $this->files($server)->putContent($file, $updated);

        // Never log what a password was changed to.
        $logged = [];
        foreach ($changes as $key => $value) {
            $logged[$key] = ($entries[$key]['type'] ?? null) === 'password' ? '(changed)' : $value;
        }

        Activity::event('server:underseer.config')
            ->property(['file' => $file, 'backup' => $backup, 'changes' => $logged])
            ->log();

        return $running && $restart;
    }

    /** The file's contents, or null when it doesn't exist. */
    private function read(Server $server, string $file): ?string
    {
        try {
            return $this->files($server)->getContent($file, self::MAX_FILE_SIZE);
        } catch (FileNotFoundException) {
            return null;
        }
    }

    /** Writes a dated copy of the file and drops the oldest copies past the limit. */
    private function backup(Server $server, string $file, string $contents): string
    {
        $name = str_replace('/', '_', $file) . '.' . now()->format('Y-m-d_His');
        $path = self::BACKUP_DIR . '/' . $name;

        // Wings makes the folders it needs when writing a file.
        $this->files($server)->putContent($path, $contents);

        try {
            $prefix = str_replace('/', '_', $file) . '.';
            $copies = collect($this->files($server)->getDirectory(self::BACKUP_DIR))
                ->filter(fn ($entry) => is_array($entry) && ($entry['file'] ?? false) && str_starts_with($entry['name'], $prefix))
                ->pluck('name')
                ->sort()
                ->values();

            $keep = max(1, (int) config('underseer.config.backups', 10));
            if ($copies->count() > $keep) {
                $this->files($server)->deleteFiles(self::BACKUP_DIR, $copies->slice(0, $copies->count() - $keep)->all());
            }
        } catch (Exception $exception) {
            // Old copies piling up is not worth failing the save over.
            report($exception);
        }

        return $path;
    }

    private function files(Server $server): DaemonFileRepository
    {
        return (new DaemonFileRepository())->setServer($server);
    }
}
