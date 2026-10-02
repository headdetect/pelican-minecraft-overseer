<?php

// Seeds the local dev panel with what the web installer and the admin pages
// would create by hand. script/up runs each step inside the panel container:
//
//   php /script-lib/seed.php node      create the node, print the wings config
//   php /script-lib/seed.php server    create the egg, allocations and server
//   php /script-lib/seed.php status    print the server's install state
//   php /script-lib/seed.php configure write server.properties, EULA, mods
//   php /script-lib/seed.php mods      download any missing mods
//   php /script-lib/seed.php start     start the server
//   php /script-lib/seed.php render    render the map around spawn once
//   php /script-lib/seed.php uuid      print the server's uuid
//
// Every step is safe to run again. It skips what already exists.

use App\Models\Allocation;
use App\Models\Egg;
use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Repositories\Daemon\DaemonServerRepository;
use App\Services\Allocations\AssignmentService;
use App\Services\Eggs\Sharing\EggImporterService;
use App\Services\Servers\ServerCreationService;
use Headdetect\Overseer\Services\Map\MapService;
use Headdetect\Overseer\Services\Rcon\RconConnector;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const EGG_URL = 'https://raw.githubusercontent.com/pelican-eggs/minecraft/main/java/fabric/egg-fabric.yaml';
const GAME_PORT = 25565;
const RCON_PORT = 25575;
const RCON_PASSWORD = 'overseer-dev';
// squaremap's default is 8080, which is often taken on a laptop.
const MAP_PORT = 8100;

$dataDir = getenv('DEV_DATA') ?: throw new RuntimeException('DEV_DATA is not set');
$wingsPort = (int) (getenv('WINGS_PORT') ?: 8891);
$step = $argv[1] ?? '';

// Laravel's handler prints an uncaught exception but exits 0, which hides the
// failure from script/up. Print it and exit 1 instead.
set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, 'seed: ' . $e::class . ': ' . $e->getMessage() . "\n");
    exit(1);
});

function node(): ?Node
{
    return Node::query()->where('name', 'dev')->first();
}

function server(): ?Server
{
    return Server::query()->where('name', 'dev')->first();
}

/**
 * Finds the newest stable Minecraft version that both squaremap and Fabric API
 * have a Fabric build for, unless MC_VERSION is set.
 *
 * @return array{string, array<string, string>} the version, and mod jar URLs by file name
 */
function versions(): array
{
    $builds = fn (string $project, ?string $game = null) => Http::get("https://api.modrinth.com/v2/project/$project/version", array_filter([
        'loaders' => '["fabric"]',
        'game_versions' => $game ? json_encode([$game]) : null,
    ]))->throw()->json();

    $stable = fn (string $v) => preg_match('/^[\d.]+$/', $v) === 1;

    $game = getenv('MC_VERSION') ?: null;
    if (!$game) {
        foreach ($builds('squaremap') as $build) {
            foreach ($build['game_versions'] as $candidate) {
                if ($stable($candidate) && $builds('fabric-api', $candidate)) {
                    $game = $candidate;
                    break 2;
                }
            }
        }
    }
    throw_unless($game, new RuntimeException('No Minecraft version has both squaremap and Fabric API builds.'));

    // Chunky is for the Tools tab. It is optional, so a version without a
    // Chunky build still works.
    $jars = [];
    foreach (['fabric-api' => true, 'squaremap' => true, 'chunky' => false] as $project => $required) {
        $build = $builds($project, $game)[0] ?? null;
        if (!$build) {
            throw_if($required, new RuntimeException("$project has no Fabric build for $game"));
            echo "seed: $project has no Fabric build for $game, skipping\n";
            continue;
        }
        $file = collect($build['files'])->firstWhere('primary', true) ?? $build['files'][0];
        $jars[$file['filename']] = $file['url'];
    }

    return [$game, $jars];
}

switch ($step) {
    case 'node':
        $node = node() ?? Node::create([
            'name' => 'dev',
            'description' => 'Local wings for Overseer development',
            'scheme' => 'http',
            'fqdn' => 'localhost',
            'public' => true,
            'behind_proxy' => false,
            'maintenance_mode' => false,
            'memory' => 0,
            'memory_overallocate' => -1,
            'disk' => 0,
            'disk_overallocate' => -1,
            'cpu' => 0,
            'cpu_overallocate' => -1,
            'upload_size' => 256,
            'daemon_listen' => $wingsPort,
            'daemon_connect' => $wingsPort,
            'daemon_sftp' => 2023,
            'daemon_sftp_alias' => '',
            'daemon_base' => "$dataDir/wings/volumes",
        ]);
        // Keep the data path current, so the stack still starts after the
        // checkout or the data directory moves.
        $node->update(['daemon_base' => "$dataDir/wings/volumes"]);

        // The panel's config only sets the server data path. Every other wings
        // path defaults to a system directory, so point them all at DEV_DATA.
        // The 172.18 default subnet often clashes with other compose projects.
        $config = $node->refresh()->getConfiguration();
        $config['system'] += [
            'root_directory' => "$dataDir/wings/lib",
            'log_directory' => "$dataDir/wings/log",
            'archive_directory' => "$dataDir/wings/archives",
            'backup_directory' => "$dataDir/wings/backups",
            'tmp_directory' => "$dataDir/wings/tmp",
            // Wings bind-mounts these into each game container by host path.
            'user' => ['passwd' => ['directory' => "$dataDir/wings/etc"]],
            'machine_id' => ['directory' => "$dataDir/wings/machine-id"],
        ];
        $config['docker'] = [
            'network' => [
                'name' => 'overseer_dev',
                // Wings attaches containers to this one, which is a separate key.
                'network_mode' => 'overseer_dev',
                'interface' => '172.29.0.1',
                'interfaces' => ['v4' => ['subnet' => '172.29.0.0/16', 'gateway' => '172.29.0.1']],
            ],
        ];
        echo Yaml::dump($config, 6, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
        break;

    case 'server':
        if (server()) {
            break;
        }

        $node = node() ?? throw new RuntimeException('Run the node step first.');
        $egg = Egg::query()->where('name', 'Fabric')->first()
            ?? app(EggImporterService::class)->fromUrl(EGG_URL);

        if (!Allocation::query()->where('node_id', $node->id)->exists()) {
            app(AssignmentService::class)->handle($node, [
                'allocation_ip' => '0.0.0.0',
                'allocation_ports' => [(string) GAME_PORT, (string) RCON_PORT, (string) MAP_PORT],
            ]);
        }
        $allocation = fn (int $port) => Allocation::query()->where('node_id', $node->id)->where('port', $port)->value('id');

        [$game] = versions();
        echo "seed: Minecraft $game\n";

        $server = app(ServerCreationService::class)->handle([
            'name' => 'dev',
            'owner_id' => User::query()->where('email', 'admin@overseer.test')->value('id'),
            'egg_id' => $egg->id,
            'image' => $egg->docker_images['Java 25'] ?? end($egg->docker_images),
            'memory' => 4096,
            'swap' => 0,
            'disk' => 20000,
            'io' => 500,
            'cpu' => 0,
            'allocation_id' => $allocation(GAME_PORT),
            'allocation_additional' => [$allocation(RCON_PORT), $allocation(MAP_PORT)],
            'environment' => [
                'MC_VERSION' => $game,
                'FABRIC_VERSION' => 'latest',
                'LOADER_VERSION' => 'latest',
                'SERVER_JARFILE' => 'server.jar',
            ],
            'start_on_completion' => false,
        ]);
        echo "seed: created server {$server->uuid}\n";
        break;

    case 'status':
        $server = server() ?? throw new RuntimeException('No dev server yet.');
        echo $server->status?->value ?? 'installed', "\n";
        break;

    case 'uuid':
        echo server()?->uuid, "\n";
        break;

    case 'configure':
        $server = server() ?? throw new RuntimeException('Run the server step first.');
        $files = (new DaemonFileRepository())->setServer($server);

        // Skip a server that already booted once, so a re-run keeps its world
        // and any settings changed through Overseer.
        $existing = collect($files->getDirectory('/'))->pluck('name');
        if ($existing->contains('server.properties')) {
            echo "seed: server.properties exists, skipping\n";
            break;
        }

        $files->putContent('eula.txt', "eula=true\n");
        $files->putContent('server.properties', implode("\n", [
            'motd=Overseer dev',
            'enable-rcon=true',
            'rcon.password=' . RCON_PASSWORD,
            'rcon.port=' . RCON_PORT,
            'broadcast-rcon-to-ops=false',
            '',
        ]));
        // On Fabric, squaremap reads squaremap/config.yml in the server root and
        // fills in every key this file leaves out.
        $files->putContent('squaremap/config.yml', "config-version: 2\nsettings:\n  internal-webserver:\n    port: " . MAP_PORT . "\n");

        [, $jars] = versions();
        foreach ($jars as $name => $url) {
            $files->pull($url, 'mods', ['filename' => $name, 'foreground' => true]);
            echo "seed: downloaded mods/$name\n";
        }
        break;

    case 'mods':
        $files = (new DaemonFileRepository())->setServer(server() ?? throw new RuntimeException('Run the server step first.'));
        $existing = collect($files->getDirectory('mods'))->pluck('name');
        [, $jars] = versions();
        foreach ($jars as $name => $url) {
            if (!$existing->contains($name)) {
                $files->pull($url, 'mods', ['filename' => $name, 'foreground' => true]);
                echo "seed: downloaded mods/$name\n";
            }
        }
        break;

    case 'start':
        (new DaemonServerRepository())->setServer(server())->power('start');
        break;

    case 'render':
        // A new world only has the chunks around spawn, and squaremap only draws
        // chunks that exist. Generate 16 by 16 chunks around spawn with forceload,
        // then have squaremap render them, so the Live Map shows terrain before
        // anyone joins.
        $server = server() ?? throw new RuntimeException('Run the server step first.');
        $files = (new DaemonFileRepository())->setServer($server);
        // The marker is in the world directory, so a regenerated world renders again.
        $rendered = fn () => collect($files->getDirectory('world'))->pluck('name')->contains('.dev-rendered');
        if (rescue($rendered, false, report: false)) {
            echo "seed: map already rendered, skipping\n";
            break;
        }

        // Runs one RCON command. Generating chunks blocks the server thread for
        // longer than the RCON timeout, so on a timeout wait for the server to
        // answer again. The command still runs.
        $rcon = function (string $command) use ($server): void {
            try {
                app(RconConnector::class)->connect($server)->command($command);
            } catch (Throwable) {
                for ($i = 0; $i < 60; $i++, sleep(2)) {
                    try {
                        app(RconConnector::class)->connect($server)->command('list');

                        return;
                    } catch (Throwable) {
                    }
                }
                throw new RuntimeException("RCON stopped answering after: $command");
            }
        };

        // MapService reports ready once the game and squaremap's web server are up.
        for ($waited = 0; ($source = app(MapService::class)->source($server, true))['status'] !== MapService::READY; $waited += 5) {
            throw_if($waited >= 300, new RuntimeException("squaremap was not ready after 300s ({$source['status']})."));
            sleep(5);
        }

        $world = collect($source['worlds'])->firstWhere('type', 'overworld') ?? $source['worlds'][0];
        $chunkX = (int) floor($world['spawn']['x'] / 16) * 16;
        $chunkZ = (int) floor($world['spawn']['z'] / 16) * 16;
        $rcon(sprintf('forceload add %d %d %d %d', $chunkX - 128, $chunkZ - 128, $chunkX + 127, $chunkZ + 127));
        $rcon('forceload remove all');
        $rcon(sprintf('squaremap radiusrender %s 128 %d %d', preg_replace('/_/', ':', $world['name'], 1), $world['spawn']['x'], $world['spawn']['z']));

        $files->putContent('world/.dev-rendered', '');
        echo "seed: rendered the map around spawn\n";
        break;

    default:
        fwrite(STDERR, "usage: seed.php node|server|status|configure|mods|start|render|uuid\n");
        exit(2);
}
