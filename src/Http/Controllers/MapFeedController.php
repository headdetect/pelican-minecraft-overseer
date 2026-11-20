<?php

namespace Headdetect\Overseer\Http\Controllers;

use App\Enums\ContainerStatus;
use App\Models\Server;
use Headdetect\Overseer\Services\ConsoleService;
use Headdetect\Overseer\Services\MapFeed;
use Headdetect\Overseer\Services\Tools\Chunky;
use Headdetect\Overseer\Support\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The Overview's live feed, as one long request (server-sent events) per open
 * page. It holds one RCON connection for the life of the page, instead of
 * connecting for every refresh: each RCON login is two lines in the server log.
 *
 * The page's refresh control sets the pace of the full feed. Each tick POSTs to
 * poke(), which leaves a note in the cache. The stream checks for notes a few
 * times a second and answers with the next feed. It is a plain route instead of
 * a Livewire call, because Livewire runs one request at a time per page.
 *
 * Between full feeds, the stream sends only the map markers, every second with
 * squaremap or every two seconds over RCON. It does this only while the pokes
 * keep coming, so it stops when automatic refresh is off or the tab is hidden.
 *
 * The request holds a PHP-FPM worker while the page is open. It ends when the
 * browser goes away (noticed at the next heartbeat) or after an hour, when the
 * browser reconnects on its own, so a missed disconnect can't hold a worker
 * for good and the login is checked again.
 */
class MapFeedController extends Controller
{
    /** How often the stream looks for a poke. */
    private const POLL_MS = 200;

    /** A comment line this often, so the request notices when the browser has gone. */
    private const HEARTBEAT = 3.0;

    private const LIFETIME = 3600;

    /** After RCON fails, wait this long before connecting again. */
    private const RCON_RETRY = 10.0;

    /** The Chunky banner, at most this often unless the refresh was a click. */
    private const CHUNKY_EVERY = 15.0;

    /** Marker positions this often between full feeds. squaremap updates its players.json about once a second. */
    private const POSITIONS_EVERY = 1.0;

    /** Without squaremap, each position costs several RCON queries per player, so less often. */
    private const RCON_POSITIONS_EVERY = 2.0;

    /**
     * Keep sending positions this long past the page's refresh interval. If no
     * poke comes by then, the page stopped refreshing. A new stream gets this
     * long before its first poke.
     */
    private const LIVE_GRACE = 10.0;

    public function stream(Server $server, MapFeed $feed, Chunky $chunky, ConsoleService $console): StreamedResponse
    {
        abort_unless(Permission::allows(Permission::MAP_VIEW, $server), 403);

        $id = bin2hex(random_bytes(16));
        $key = self::pokeKey($id);

        return response()->stream(function () use ($server, $feed, $chunky, $console, $id, $key) {
            set_time_limit(0);
            // Leave the loop ourselves when the browser goes, so RCON is closed cleanly.
            ignore_user_abort(true);
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            $write = function (string $text) {
                echo $text;
                flush();
            };
            $send = fn (string $event, array $data) => $write("event: $event\ndata: " . json_encode($data) . "\n\n");

            $chunkyAt = null;
            $tick = function (int $seq, bool $manual) use ($server, $feed, $chunky, $console, $send, &$chunkyAt) {
                $console->retryRcon(self::RCON_RETRY);
                try {
                    $data = ['seq' => $seq, 'feed' => $feed->payload($server)];

                    $running = $server->retrieveStatus() === ContainerStatus::Running;
                    if ($manual || $chunkyAt === null || microtime(true) - $chunkyAt >= self::CHUNKY_EVERY) {
                        $chunkyAt = microtime(true);
                        $data['chunky'] = $running
                            ? Cache::remember("overseer:chunky:$server->uuid", now()->addSeconds(10), fn () => $chunky->running($server))
                            : [];
                    }

                    // Keeps the RCON warning (and the other pages' check) current without a connection of its own.
                    $data['rcon'] = $console->checkRcon($server);

                    $send('feed', $data);
                } catch (Throwable $exception) {
                    report($exception);
                    $send('failed', ['seq' => $seq]);
                }
            };

            try {
                // The browser waits this long before reconnecting after a drop.
                $write("retry: 3000\n\n");
                $send('hello', ['stream' => $id]);
                $tick(0, true);

                $handled = 0;
                $beat = $positionsAt = microtime(true);
                $liveUntil = microtime(true) + self::LIVE_GRACE;
                $end = time() + self::LIFETIME;
                while (!connection_aborted() && time() < $end) {
                    usleep(self::POLL_MS * 1000);
                    $now = microtime(true);

                    $poke = Cache::get($key);
                    if (is_array($poke) && $poke['seq'] > $handled) {
                        $handled = $poke['seq'];
                        $interval = $poke['interval'] ?? 0;
                        $liveUntil = $interval > 0 ? $now + $interval + self::LIVE_GRACE : 0;
                        $tick($handled, $poke['manual']);
                        $beat = $positionsAt = microtime(true);
                    } elseif ($now < $liveUntil && $now - $positionsAt >= ($feed->usesSquaremap($server) ? self::POSITIONS_EVERY : self::RCON_POSITIONS_EVERY)) {
                        $console->retryRcon(self::RCON_RETRY);
                        // A failure skips this one quietly. The next full feed reports it.
                        try {
                            $players = $feed->positions($server);
                        } catch (Throwable) {
                            $players = null;
                        }
                        if ($players !== null) {
                            $send('players', ['players' => $players]);
                            $beat = microtime(true);
                        }
                        $positionsAt = microtime(true);
                    } elseif ($now - $beat >= self::HEARTBEAT) {
                        $write(": ping\n\n");
                        $beat = microtime(true);
                    }
                }
            } finally {
                $console->disconnect();
                Cache::forget($key);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            // Proxies that buffer responses (nginx, for one) pass this one through as it is written.
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /** Asks the page's stream for a fresh feed. The answer comes over the stream. */
    public function poke(Request $request, Server $server, string $stream): JsonResponse
    {
        abort_unless(Permission::allows(Permission::MAP_VIEW, $server), 403);

        $data = $request->validate([
            'seq' => ['required', 'integer', 'min:1'],
            'manual' => ['boolean'],
            // The page's automatic refresh interval in seconds, 0 when it's off.
            'interval' => ['integer', 'min:0', 'max:3600'],
        ]);

        Cache::put(self::pokeKey($stream), [
            'seq' => (int) $data['seq'],
            'manual' => (bool) ($data['manual'] ?? false),
            'interval' => (int) ($data['interval'] ?? 0),
        ], now()->addSeconds(self::LIFETIME));

        return response()->json(['ok' => true]);
    }

    /** Per user, so nobody can poke another user's stream. */
    private static function pokeKey(string $stream): string
    {
        return 'overseer:feed-poke:' . user()?->id . ":$stream";
    }
}
