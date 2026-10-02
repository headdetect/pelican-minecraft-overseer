<?php

namespace Headdetect\Overseer\Services;

use App\Enums\ContainerStatus;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Services\Servers\EnvironmentService;
use Exception;
use Headdetect\Overseer\Support\ServerStats;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Collects the quick stats at the top of the Overview page.
 */
class OverviewService
{
    /** The table and file the Modpack Manager plugin uses to record the installed pack. */
    private const MODPACK_TABLE = 'modpack_installs';

    private const MODPACK_FILE = '.modpack-manager.json';

    public function __construct(private readonly ConsoleService $console) {}

    /**
     * @return array{
     *     running: bool,
     *     resources: array{cpu: ?float, cpu_limit: int, memory: ?int, memory_limit: int, disk: ?int, disk_limit: int, uptime: ?int},
     *     version: ?string,
     * }
     */
    public function stats(Server $server): array
    {
        $running = $server->retrieveStatus() === ContainerStatus::Running;

        return [
            'running' => $running,
            'resources' => $this->resources($server, $running),
            'version' => $this->version($server, $running),
        ];
    }

    public function gameTime(Server $server): ?array
    {
        $day = $this->console->query($server, 'time query day');
        $daytime = $day !== null && str_contains($day, 'The time is')
            ? $this->console->query($server, 'time query daytime')
            : null;

        return ServerStats::gameTime($day, $daytime);
    }

    /** Wings reports usage. The limits are the server's, in MiB and percent, where 0 means no limit. */
    private function resources(Server $server, bool $running): array
    {
        $usage = $running ? $server->retrieveResources() : [];

        return [
            'cpu' => isset($usage['cpu_absolute']) ? (float) $usage['cpu_absolute'] : null,
            'cpu_limit' => (int) $server->cpu,
            'memory' => isset($usage['memory_bytes']) ? (int) $usage['memory_bytes'] : null,
            'memory_limit' => (int) $server->memory * 1024 * 1024,
            'disk' => isset($usage['disk_bytes']) ? (int) $usage['disk_bytes'] : null,
            'disk_limit' => (int) $server->disk * 1024 * 1024,
            'uptime' => isset($usage['uptime']) && $running ? (int) $usage['uptime'] : null,
        ];
    }

    /** The version only changes on a restart, so ask once every 10 minutes. */
    private function version(Server $server, bool $running): ?string
    {
        $key = "overseer:version:$server->uuid";
        if ($cached = Cache::get($key)) {
            return $cached;
        }

        $version = $running ? ServerStats::version($this->console->query($server, 'version')) : null;
        $version ??= ServerStats::versionFromEnvironment(app(EnvironmentService::class)->handle($server));

        if ($version !== null) {
            Cache::put($key, $version, now()->addMinutes(10));
        }

        return $version;
    }

    public function modpack(Server $server): ?array
    {
        return Cache::remember("overseer:modpack:$server->uuid", now()->addMinute(), function () use ($server) {
            if (Schema::hasTable(self::MODPACK_TABLE)) {
                $row = DB::table(self::MODPACK_TABLE)
                    ->where('server_id', $server->id)
                    ->where('status', 'installed')
                    ->latest('updated_at')
                    ->first();

                if ($row) {
                    return ServerStats::modpack((array) $row);
                }
            }

            try {
                $json = json_decode((new DaemonFileRepository())->setServer($server)->getContent(self::MODPACK_FILE), true);

                return is_array($json) ? ServerStats::modpack($json) : null;
            } catch (FileNotFoundException) {
                return null;
            } catch (Exception $exception) {
                report($exception);

                return null;
            }
        });
    }
}
