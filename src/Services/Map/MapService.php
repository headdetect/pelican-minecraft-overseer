<?php

namespace Headdetect\Overseer\Services\Map;

use App\Enums\ContainerStatus;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use Headdetect\Overseer\Support\ServerAddress;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Finds squaremap on a server and talks to its web server from the panel.
 *
 * The browser never connects to squaremap directly: tiles go through a panel route,
 * so the map works on an HTTPS panel and squaremap's port only has to be reachable
 * from the panel, like RCON. The panel only ever dials the server's own address on
 * one of the server's own allocated ports.
 */
class MapService
{
    public const READY = 'ready';

    public const NOT_INSTALLED = 'not_installed';

    public const WEB_OFF = 'web_off';

    public const PORT_NOT_ALLOCATED = 'port_not_allocated';

    public const UNREACHABLE = 'unreachable';

    public const OFFLINE = 'offline';

    /**
     * What we know about the server's map, cached briefly so tile requests don't re-read config files.
     *
     * @return array{status: string, port: ?int, worlds: array<int, array<string, mixed>>}
     */
    public function source(Server $server, bool $fresh = false): array
    {
        $key = "overseer:map:$server->uuid";
        if ($fresh) {
            Cache::forget($key);
        }

        if ($cached = Cache::get($key)) {
            return $cached;
        }

        $source = $this->detect($server);
        Cache::put($key, $source, now()->addSeconds($source['status'] === self::READY ? 60 : 15));

        return $source;
    }

    /**
     * Online players with their world and position, or null when squaremap can't be reached.
     *
     * @return ?array<int, array<string, mixed>>
     */
    public function players(Server $server): ?array
    {
        $source = $this->source($server);
        if ($source['status'] !== self::READY) {
            return null;
        }

        $response = $this->get($server, $source['port'], 'tiles/players.json');

        return $response?->successful() ? Squaremap::players((array) $response->json()) : null;
    }

    /** Fetches one allowed file from squaremap for the tile route. */
    public function fetch(Server $server, string $path): ?Response
    {
        $source = $this->source($server);
        if ($source['status'] !== self::READY || !Squaremap::isProxiedPath($path)) {
            return null;
        }

        return $this->get($server, $source['port'], $path);
    }

    /** @return array{status: string, port: ?int, worlds: array<int, array<string, mixed>>} */
    private function detect(Server $server): array
    {
        $result = fn (string $status, ?int $port = null, array $worlds = []) => compact('status', 'port', 'worlds');

        $config = $this->readConfig($server);
        if ($config === null) {
            return $result(self::NOT_INSTALLED);
        }

        if (!$config['enabled']) {
            return $result(self::WEB_OFF, $config['port']);
        }

        if (!$server->allocations()->where('port', $config['port'])->exists()) {
            return $result(self::PORT_NOT_ALLOCATED, $config['port']);
        }

        if ($server->retrieveStatus() !== ContainerStatus::Running) {
            return $result(self::OFFLINE, $config['port']);
        }

        $settings = $this->get($server, $config['port'], 'tiles/settings.json');
        if (!$settings?->successful()) {
            return $result(self::UNREACHABLE, $config['port']);
        }

        $worlds = [];
        foreach (Squaremap::worlds((array) $settings->json()) as $world) {
            $response = $this->get($server, $config['port'], "tiles/{$world['name']}/settings.json");
            $worlds[] = [...$world, ...Squaremap::worldSettings($response?->successful() ? (array) $response->json() : [])];
        }

        return $result($worlds ? self::READY : self::UNREACHABLE, $config['port'], $worlds);
    }

    /** @return ?array{enabled: bool, port: int} null when squaremap isn't installed */
    private function readConfig(Server $server): ?array
    {
        $files = (new DaemonFileRepository())->setServer($server);

        foreach (Squaremap::CONFIG_PATHS as $path) {
            try {
                return Squaremap::parseConfig($files->getContent($path));
            } catch (FileNotFoundException) {
                continue;
            } catch (Exception $exception) {
                report($exception);
            }
        }

        return null;
    }

    private function get(Server $server, int $port, string $path): ?Response
    {
        $host = ServerAddress::host($server, config('overseer.map.host') ?: config('overseer.rcon.host'));

        try {
            return Http::timeout((float) config('overseer.map.timeout', 3.0))
                ->withoutRedirecting()
                ->get("http://$host:$port/$path");
        } catch (Exception) {
            return null;
        }
    }
}
