<?php

namespace Headdetect\Overseer\Services\Tools;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use Headdetect\Overseer\Services\ConsoleService;
use Headdetect\Overseer\Support\Properties;
use InvalidArgumentException;

/**
 * Drives the Chunky mod or plugin over RCON to generate chunks ahead of time.
 * Chunky generates in the background, so the server keeps running normally,
 * and it saves its progress when paused or stopped.
 */
class Chunky
{
    public const SHAPES = ['square', 'circle'];

    /** Largest radius the form allows, in blocks. 10,000 is about 1.5 million chunks. */
    public const MAX_RADIUS = 10000;

    public function __construct(private readonly ConsoleService $console) {}

    /** Where Chunky saves paused tasks: config/ on Fabric and NeoForge, plugins/ on Paper. */
    private const TASK_DIRS = ['config/chunky/tasks', 'plugins/Chunky/tasks'];

    /**
     * Running tasks only, from one RCON command. The Overview polls this, so
     * it doesn't read the saved task files the way status() does.
     *
     * @return array<int, array{world: string, chunks: int, percent: float, eta: ?string, rate: ?float}>
     */
    public function running(Server $server): array
    {
        $reply = $this->console->query($server, 'chunky progress');

        return $reply === null ? [] : self::parseProgress($reply);
    }

    /**
     * Whether Chunky answers, its running tasks, and saved tasks that aren't
     * running (paused, or interrupted by a restart). installed is null when
     * RCON is off.
     *
     * @return array{installed: ?bool, tasks: array<int, array<string, mixed>>, saved: array<int, array<string, mixed>>}
     */
    public function status(Server $server): array
    {
        $reply = $this->console->query($server, 'chunky progress');
        $tasks = $reply === null ? [] : self::parseProgress($reply);
        $running = array_column($tasks, 'world');

        return [
            'installed' => $reply === null ? null : str_contains($reply, '[Chunky]'),
            'tasks' => $tasks,
            'saved' => $reply === null ? [] : array_values(array_filter($this->savedTasks($server), fn ($task) => !in_array($task['world'], $running, true))),
        ];
    }

    /**
     * Tasks Chunky saved to disk and hasn't cancelled.
     *
     * @return array<int, array{world: string, chunks: int, percent: ?float}>
     */
    private function savedTasks(Server $server): array
    {
        $files = (new DaemonFileRepository())->setServer($server);
        $tasks = [];

        foreach (self::TASK_DIRS as $dir) {
            try {
                $namespaces = $files->getDirectory($dir);
            } catch (Exception) {
                continue;
            }

            foreach ($namespaces as $namespace) {
                if (!($namespace['directory'] ?? false)) {
                    continue;
                }
                try {
                    $entries = $files->getDirectory("$dir/{$namespace['name']}");
                } catch (Exception) {
                    continue;
                }

                foreach ($entries as $entry) {
                    if (!str_ends_with($entry['name'] ?? '', '.properties')) {
                        continue;
                    }
                    try {
                        $task = self::parseTask($files->getContent("$dir/{$namespace['name']}/{$entry['name']}"));
                    } catch (Exception) {
                        $task = null;
                    }
                    if ($task) {
                        $tasks[] = $task;
                    }
                }
            }
        }

        return $tasks;
    }

    /**
     * Parses a saved task file. Null when it was cancelled or isn't a task.
     *
     * @return ?array{world: string, chunks: int, percent: ?float}
     */
    public static function parseTask(string $contents): ?array
    {
        $task = Properties::parse($contents);
        if (!isset($task['world']) || ($task['cancelled'] ?? 'false') === 'true') {
            return null;
        }

        $chunks = (int) ($task['chunks'] ?? 0);
        $radius = (int) round((float) ($task['radius'] ?? 0));
        $total = $radius >= 16 ? self::chunkCount($radius, ($task['shape'] ?? 'square') === 'circle' ? 'circle' : 'square') : 0;

        return [
            'world' => $task['world'],
            'chunks' => $chunks,
            'percent' => $total > 0 ? min(100.0, round($chunks / $total * 100, 1)) : null,
        ];
    }

    /**
     * Sets the selection and starts a task. Runs each command through the
     * audited console, and returns Chunky's reply to the start command.
     */
    public function start(Server $server, string $world, ?int $x, ?int $z, int $radius, string $shape): ?string
    {
        if (!self::isWorld($world)) {
            throw new InvalidArgumentException("\"$world\" isn't a world name.");
        }
        if (!in_array($shape, self::SHAPES, true)) {
            throw new InvalidArgumentException("\"$shape\" isn't a shape Overseer offers.");
        }
        if ($radius < 16 || $radius > self::MAX_RADIUS) {
            throw new InvalidArgumentException('The radius must be between 16 and ' . self::MAX_RADIUS . ' blocks.');
        }

        $this->run($server, "chunky world $world");
        $this->run($server, $x === null || $z === null ? 'chunky spawn' : "chunky center $x $z");
        $this->run($server, "chunky radius $radius");
        $this->run($server, "chunky shape $shape");

        // With a saved task for this world, Chunky asks to confirm replacing it.
        // The form already says so, so confirm.
        $reply = $this->run($server, 'chunky start');

        return $reply !== null && str_contains($reply, 'confirm') ? $this->run($server, 'chunky confirm') : $reply;
    }

    public function pause(Server $server): ?string
    {
        return $this->run($server, 'chunky pause');
    }

    public function continue(Server $server): ?string
    {
        return $this->run($server, 'chunky continue');
    }

    /** Chunky asks for a confirmation before it deletes a task, so send both. */
    public function cancel(Server $server): ?string
    {
        $this->run($server, 'chunky cancel');

        return $this->run($server, 'chunky confirm');
    }

    private function run(Server $server, string $command): ?string
    {
        return $this->console->run($server, 'pregenerate', $command);
    }

    /**
     * Parses "chunky progress". Each running task is one line like
     * "[Chunky] Task running for minecraft:overworld. Processed: 1040 chunks (68.38%), ETA: 0:00:12, Rate: 38.1 cps, Current: -4, -7".
     *
     * @return array<int, array{world: string, chunks: int, percent: float, eta: ?string, rate: ?float}>
     */
    public static function parseProgress(string $reply): array
    {
        // Some JVM locales print decimals with a comma.
        preg_match_all('/Task running for ([\w:.\/-]+)\. Processed: (\d+) chunks \(([\d.,]+)%\)(?:, ETA: ([\d:]+))?(?:, Rate: ([\d.,]+) cps)?/', $reply, $matches, PREG_SET_ORDER);
        $number = fn (string $value) => (float) str_replace(',', '.', $value);

        return array_map(fn (array $m) => [
            'world' => $m[1],
            'chunks' => (int) $m[2],
            'percent' => min(100.0, $number($m[3])),
            'eta' => ($m[4] ?? '') !== '' ? $m[4] : null,
            'rate' => ($m[5] ?? '') !== '' ? $number($m[5]) : null,
        ], $matches);
    }

    /** About how many chunks a selection covers. */
    public static function chunkCount(int $radius, string $shape): int
    {
        $side = intdiv(2 * $radius, 16) + 1;

        return $shape === 'circle' ? (int) round(M_PI * ($side / 2) ** 2) : $side * $side;
    }

    public static function isWorld(string $world): bool
    {
        return (bool) preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/', $world);
    }
}
