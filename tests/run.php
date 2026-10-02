<?php

// Unit tests for the parts of Overseer that don't need a running panel.
// Run with: php tests/run.php

spl_autoload_register(function (string $class) {
    $prefix = 'Headdetect\\Overseer\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use Headdetect\Overseer\Services\GameRules;
use Headdetect\Overseer\Services\Map\Squaremap;
use Headdetect\Overseer\Services\PlayerService;
use Headdetect\Overseer\Services\Rcon\RconClient;
use Headdetect\Overseer\Services\Rcon\RconException;
use Headdetect\Overseer\Services\Tools\Chunky;
use Headdetect\Overseer\Support\CommandInput;
use Headdetect\Overseer\Support\ConfigSchema;
use Headdetect\Overseer\Support\Properties;
use Headdetect\Overseer\Support\ServerAddress;
use Headdetect\Overseer\Support\ServerStats;
use Headdetect\Overseer\Support\YamlLines;

$failures = 0;
$count = 0;
function check(string $name, mixed $actual, mixed $expected): void
{
    global $failures, $count;
    $count++;
    if ($actual !== $expected) {
        $failures++;
        echo "FAIL $name\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
    }
}
function throws(string $name, callable $fn, string $class = InvalidArgumentException::class): void
{
    global $failures, $count;
    $count++;
    try {
        $fn();
        $failures++;
        echo "FAIL $name: no exception\n";
    } catch (Throwable $e) {
        if (!$e instanceof $class) {
            $failures++;
            echo "FAIL $name: threw " . get_class($e) . ' ' . $e->getMessage() . "\n";
        }
    }
}

// --- CommandInput: nothing typed in the panel may start a second command ---
check('valid name', CommandInput::playerName(' kelp_lord '), 'kelp_lord');
check('floodgate name', CommandInput::playerName('.BedrockSteve'), '.BedrockSteve');
throws('name with space', fn () => CommandInput::playerName('Steve op Griefer'));
throws('name with newline', fn () => CommandInput::playerName("Steve\nop Griefer"));
throws('name too long', fn () => CommandInput::playerName(str_repeat('a', 17)));
throws('empty name', fn () => CommandInput::playerName(''));
check('reason newlines flattened', CommandInput::text("Griefing\nop Me\r\nstop"), 'Griefing op Me stop');
check('reason colour codes removed', CommandInput::text('§cBad §lplayer'), 'Bad player');
check('reason length bounded', mb_strlen(CommandInput::text(str_repeat('x', 500))), 200);
check('command leading slash removed', CommandInput::command('/time set day'), 'time set day');
check('command one line', CommandInput::command("say hi\nstop"), 'say hi stop');

// --- Chunky ---
check('chunky progress', Chunky::parseProgress('[Chunky] Task running for minecraft:overworld. Processed: 1040 chunks (68.38%), ETA: 0:00:12, Rate: 38.1 cps, Current: -4, -7'), [['world' => 'minecraft:overworld', 'chunks' => 1040, 'percent' => 68.38, 'eta' => '0:00:12', 'rate' => 38.1]]);
check('chunky two tasks', array_column(Chunky::parseProgress("[Chunky] Task running for minecraft:overworld. Processed: 5 chunks (1.00%), ETA: 1:00:00, Rate: 2.0 cps, Current: 0, 0\n[Chunky] Task running for minecraft:the_nether. Processed: 9 chunks (2.00%), ETA: 0:30:00, Rate: 3.0 cps, Current: 0, 0"), 'world'), ['minecraft:overworld', 'minecraft:the_nether']);
check('chunky idle', Chunky::parseProgress('[Chunky] No tasks running.'), []);
check('chunky square count', Chunky::chunkCount(300, 'square'), 1444);
check('chunky circle smaller', Chunky::chunkCount(300, 'circle') < 1444, true);
check('chunky world name', Chunky::isWorld('minecraft:the_nether'), true);
check('chunky world injection', Chunky::isWorld('minecraft:overworld; stop'), false);

// --- Player roster ---
$cache = [['name' => 'Doobie', 'uuid' => 'AAAAAAAA-0000-0000-0000-000000000001'], ['name' => 'kelp_lord', 'uuid' => 'aaaaaaaa-0000-0000-0000-000000000002'], ['name' => 'lookup_only', 'uuid' => 'aaaaaaaa-0000-0000-0000-000000000009']];
$list = [['name' => 'NewFriend', 'uuid' => 'aaaaaaaa-0000-0000-0000-000000000003'], ['name' => 'kelp_lord', 'uuid' => 'aaaaaaaa-0000-0000-0000-000000000002']];
$seen = ['aaaaaaaa-0000-0000-0000-000000000001' => 1000, 'aaaaaaaa-0000-0000-0000-000000000002' => 2000];
$roster = PlayerService::buildRoster($cache, $list, $seen, ['Doobie']);
check('roster order', array_column($roster, 'name'), ['Doobie', 'kelp_lord', 'NewFriend']);
check('roster online first', $roster[0]['online'], true);
check('roster last seen', $roster[1]['last_seen'], 2000);
check('roster whitelisted never joined', $roster[2]['last_seen'], null);
check('roster skips usercache lookups', in_array('lookup_only', array_column($roster, 'name'), true), false);
check('roster online without records', PlayerService::buildRoster([], [], [], ['Stranger'])[0], ['name' => 'Stranger', 'uuid' => null, 'last_seen' => null, 'online' => true]);

// --- RCON exposure ---
check('bind-all is public', ServerAddress::isPublic('0.0.0.0'), true);
check('public ip is public', ServerAddress::isPublic('51.75.10.20'), true);
check('docker bridge is private', ServerAddress::isPublic('172.18.0.1'), false);
check('loopback is private', ServerAddress::isPublic('127.0.0.1'), false);
check('lan is private', ServerAddress::isPublic('10.0.0.5'), false);

// --- Overview stats ---
check('time 26.1 timeline', ServerStats::gameTime('Timeline minecraft:day is at 3153 tick(s)'), ['day' => 1, 'clock' => '09:09', 'phase' => 'day']);
check('time 26.1 later day', ServerStats::gameTime('Timeline minecraft:day is at 66000 tick(s)'), ['day' => 3, 'clock' => '00:00', 'phase' => 'night']);
check('time older versions', ServerStats::gameTime('The time is 4', 'The time is 12500'), ['day' => 5, 'clock' => '18:30', 'phase' => 'sunset']);
check('time older daytime keeps counting', ServerStats::gameTime('The time is 0', 'The time is 47000')['clock'], '05:00');
check('time unknown reply', ServerStats::gameTime('Unknown or incomplete command'), null);
check('time no rcon', ServerStats::gameTime(null), null);
check('clock sunrise', ServerStats::clock(0, 23500)['phase'], 'sunrise');
check('version vanilla', ServerStats::version('Server version info:id = 26.1.2name = 26.1.2data = 4790series = main'), '26.1.2');
check('version vanilla lines', ServerStats::version("Server version info:\nid = 1.21.8\nname = 1.21.8\ndata = 4440"), '1.21.8');
check('version paper', ServerStats::version('This server is running Paper version 1.21.1-130-master@b48403b (2024-10-23T08:39:46Z) (Implementing API version 1.21.1-R0.1-SNAPSHOT) (MC: 1.21.1)'), '1.21.1');
check('version unknown', ServerStats::version('Unknown or incomplete command'), null);
check('version from egg', ServerStats::versionFromEnvironment(['MC_VERSION' => '26.1.2']), '26.1.2');
check('version egg latest ignored', ServerStats::versionFromEnvironment(['MC_VERSION' => 'latest', 'MINECRAFT_VERSION' => '1.20.1']), '1.20.1');
check('modpack from db row', ServerStats::modpack(['provider' => 'modrinth', 'modpack_id' => '1KVo5zza', 'modpack_name' => 'Fabulously Optimized', 'modpack_version' => '6.4.0']), ['name' => 'Fabulously Optimized', 'version' => '6.4.0', 'provider' => 'modrinth', 'url' => 'https://modrinth.com/modpack/1KVo5zza']);
check('modpack from metadata file', ServerStats::modpack(['provider' => 'curseforge', 'modpack_id' => '715572', 'name' => 'All the Mods 9', 'version' => null])['url'], 'https://www.curseforge.com/projects/715572');
check('modpack bad id gets no link', ServerStats::modpack(['provider' => 'modrinth', 'modpack_id' => '../x', 'name' => 'Pack'])['url'], null);
check('modpack html stripped', ServerStats::modpack(['name' => '<b>Pack</b>'])['name'], 'Pack');
check('modpack from modrinth egg', ServerStats::modpackFromIndex(['formatVersion' => 1, 'game' => 'minecraft', 'versionId' => '2.3.0', 'name' => 'Nurps SMP', 'dependencies' => ['minecraft' => '26.1.2']], 'nurps-smp'), ['name' => 'Nurps SMP', 'version' => '2.3.0', 'provider' => 'modrinth', 'url' => 'https://modrinth.com/modpack/nurps-smp']);
check('modpack from zip upload has no link', ServerStats::modpackFromIndex(['name' => 'Pack', 'versionId' => '1'], 'zip')['url'], null);
check('modpack missing name', ServerStats::modpack(['provider' => 'modrinth']), null);
check('uptime days', ServerStats::uptime(3 * 86400000 + 4 * 3600000), '3d 4h');
check('uptime minutes', ServerStats::uptime(12 * 60000 + 59000), '12m');

// --- Give and teleport ---
check('item id plain', CommandInput::itemId(' Diamond '), 'diamond');
check('item id modded', CommandInput::itemId('create:wrench'), 'create:wrench');
throws('item id with nbt', fn () => CommandInput::itemId('diamond_sword{Enchantments:[]}'));
check('teleport to player', CommandInput::teleport('Doobie', ['to' => 'player', 'target' => 'kelp_lord']), 'tp Doobie kelp_lord');
check('teleport to coords', CommandInput::teleport('Doobie', ['to' => 'coords', 'x' => '10', 'y' => 64, 'z' => '-20.7', 'dimension' => 'minecraft:the_nether']), 'execute in minecraft:the_nether run tp Doobie 10 64 -20');
throws('teleport bad target', fn () => CommandInput::teleport('Doobie', ['to' => 'player', 'target' => 'x; stop']));
throws('teleport bad dimension', fn () => CommandInput::teleport('Doobie', ['to' => 'coords', 'x' => 0, 'y' => 0, 'z' => 0, 'dimension' => 'minecraft:overworld run stop']));
throws('item id with second command', fn () => CommandInput::itemId('diamond 64\nop Me'));

// --- server.properties ---
$props = Properties::parse(<<<'TXT'
#Minecraft server properties
#Tue Sep 30 12:00:00 UTC 2026
enable-rcon=true
rcon.password=s3cr\=t
rcon.port=25575
motd=Welcome to Doobie's Kingdom
  level-seed=
server-ip:
! another comment
pvp = false
TXT);
check('bool true', Properties::bool($props, 'enable-rcon'), true);
check('escaped equals', $props['rcon.password'], 's3cr=t');
check('unicode escape', $props['motd'], "Welcome to Doobie's Kingdom");
check('empty value', $props['level-seed'], '');
check('colon separator', $props['server-ip'], '');
check('spaces around equals', $props['pvp'], 'false');
check('missing key default', Properties::bool($props, 'white-list', true), true);
check('comments skipped', array_key_exists('!', $props) || array_key_exists('#Minecraft', $props), false);

// --- server.properties writing: only the changed lines move ---
$file = "#Minecraft server properties\n#Tue Sep 30 12:00:00 UTC 2026\npvp=true\nmotd=Old\n\n# kept comment\nlevel-type=minecraft\\:normal\nrcon.password=s3cr\\=t\n";
$updated = Properties::update($file, ['pvp' => 'false']);
check('update changes one line', $updated, str_replace('pvp=true', 'pvp=false', $file));
check('update keeps the rest', Properties::parse($updated)['rcon.password'], 's3cr=t');
check('update escapes like Java', Properties::update("level-type=x\n", ['level-type' => 'minecraft:flat']), "level-type=minecraft\\:flat\n");
check('update escapes = and #', Properties::update('', ['motd' => 'a=b #1']), "motd=a\\=b \\#1\n");
check('update leading space', Properties::update('', ['motd' => ' hi']), "motd=\\ hi\n");
check('update round trip', Properties::parse(Properties::update($file, ['motd' => "Doobie's: Kingdom \\o/ #1"]))['motd'], "Doobie's: Kingdom \\o/ #1");
check('update adds missing key at the end', Properties::update("a=1\n", ['b' => '2']), "a=1\nb=2\n");
check('update keeps CRLF', Properties::update("a=1\r\nb=2\r\n", ['a' => '3']), "a=3\r\nb=2\r\n");
check('update no trailing newline kept', Properties::update('a=1', ['a' => '2']), 'a=2');
check('update ignores commented key', Properties::update("#pvp=true\npvp=true\n", ['pvp' => 'false']), "#pvp=true\npvp=false\n");
check('update newline cannot add a key', Properties::parse(Properties::update('', ['motd' => "hi\nop=me"])), ['motd' => "hi\nop=me"]);

// --- Paper YAML: change one value, keep comments ---
$yaml = <<<'YAML'
# This is the world defaults configuration file for Paper.
# Lots of comments here.
_version: 31
anticheat:
  anti-xray:
    enabled: false # off by default
    engine-mode: 1
    hidden-blocks:
    - copper_ore
chunks:
  max-auto-save-chunks-per-tick: 24
entities:
  spawning:
    despawn-ranges:
      monster:
        hard: 128
        soft: 32
    per-player-mob-spawns: true
environment:
  treasure-maps:
    enabled: 'true'
  nether-ceiling-void-damage-height: disabled
collisions:
  max-entity-collisions: 8
YAML;
check('yaml get nested', YamlLines::get($yaml, 'anticheat.anti-xray.enabled'), 'false');
check('yaml get deep', YamlLines::get($yaml, 'entities.spawning.despawn-ranges.monster.hard'), '128');
check('yaml get quoted', YamlLines::get($yaml, 'environment.treasure-maps.enabled'), 'true');
check('yaml get sibling after nested block', YamlLines::get($yaml, 'entities.spawning.per-player-mob-spawns'), 'true');
check('yaml get mapping is not a value', YamlLines::get($yaml, 'anticheat.anti-xray'), null);
check('yaml get list is not a value', YamlLines::get($yaml, 'anticheat.anti-xray.hidden-blocks'), null);
check('yaml get missing', YamlLines::get($yaml, 'anticheat.nope.enabled'), null);
check('yaml get wrong level', YamlLines::get($yaml, 'enabled'), null);
$changed = YamlLines::set($yaml, 'anticheat.anti-xray.enabled', 'true');
check('yaml set keeps comment', explode("\n", $changed)[5], '    enabled: true # off by default');
check('yaml set changes one line', count(array_diff_assoc(explode("\n", $changed), explode("\n", $yaml))), 1);
check('yaml set other key', YamlLines::get(YamlLines::set($yaml, 'collisions.max-entity-collisions', '2'), 'collisions.max-entity-collisions'), '2');
check('yaml set missing', YamlLines::set($yaml, 'nope.nope', '1'), null);
check('yaml scalar plain', YamlLines::scalar('24'), '24');
check('yaml scalar needs quotes', YamlLines::scalar('a: b'), "'a: b'");
check('yaml scalar empty', YamlLines::scalar(''), "''");

// --- Config schema ---
foreach (array_keys(ConfigSchema::SOURCES) as $source) {
    foreach (ConfigSchema::entries($source) as $key => $entry) {
        $where = "$source/$key";
        check("$where has title and help", is_string($entry['title'] ?? null) && is_string($entry['help'] ?? null), true);
        check("$where help is one short line", mb_strlen($entry['help']) <= 100 && !str_contains($entry['help'], "\n"), true);
        check("$where type", in_array($entry['type'], ['bool', 'int', 'range', 'enum', 'string', 'password'], true), true);
        if (in_array($entry['type'], ['int', 'range'], true)) {
            check("$where bounds", isset($entry['min'], $entry['max']) && $entry['min'] < $entry['max'], true);
        }
        if ($entry['type'] === 'enum') {
            check("$where options", is_array($entry['options'] ?? null) && $entry['options'] !== [], true);
        }
        if ($source === 'rules') {
            check("$where has old name", array_key_exists('old', $entry), true);
        }
    }
}
$int = ['title' => 'Max players', 'type' => 'int', 'min' => 1, 'max' => 1000];
check('toFile int', ConfigSchema::toFile($int, '30'), '30');
check('toFile int from number', ConfigSchema::toFile($int, 30), '30');
throws('toFile int too big', fn () => ConfigSchema::toFile($int, 5000));
throws('toFile int not a number', fn () => ConfigSchema::toFile($int, '3; op me'));
check('toFile bool', ConfigSchema::toFile(['title' => 'PVP', 'type' => 'bool'], false), 'false');
$enum = ['title' => 'Difficulty', 'type' => 'enum', 'options' => ['easy' => 'Easy', 'hard' => 'Hard']];
check('toFile enum', ConfigSchema::toFile($enum, 'hard'), 'hard');
throws('toFile enum unknown', fn () => ConfigSchema::toFile($enum, 'hard; stop'));
check('toFile text one line', ConfigSchema::toFile(['title' => 'MOTD', 'type' => 'string'], "Hi\nthere"), 'Hi there');
throws('toFile text too long', fn () => ConfigSchema::toFile(['title' => 'MOTD', 'type' => 'string', 'max' => 5], 'too long'));
check('fromFile bool', ConfigSchema::fromFile(['type' => 'bool'], 'TRUE'), true);
check('fromFile bad bool hidden', ConfigSchema::fromFile(['type' => 'bool'], 'maybe'), null);
check('fromFile int', ConfigSchema::fromFile(['type' => 'int'], '-1'), -1);
check('fromFile bad int hidden', ConfigSchema::fromFile(['type' => 'int'], 'default'), null);
check('fromFile password never sent', ConfigSchema::fromFile(['type' => 'password'], 'hunter2'), '');
check('live command template', ConfigSchema::liveCommand(['live' => 'difficulty {value}'], 'hard'), 'difficulty hard');
check('live command map', ConfigSchema::liveCommand(['live' => ['true' => 'whitelist on', 'false' => 'whitelist off']], 'false'), 'whitelist off');
check('live command none', ConfigSchema::liveCommand(['restart' => true], '10'), null);
check('field names have no dots', ConfigSchema::fieldName('rcon.port') === ConfigSchema::fieldName('rcon.port') && !str_contains(ConfigSchema::fieldName('rcon.port'), '.'), true);
check('search matches title', ConfigSchema::matches('pvp', ['title' => 'Player vs player', 'help' => 'x'], 'player'), true);
check('search matches key', ConfigSchema::matches('view-distance', ['title' => 'View', 'help' => 'x'], 'VIEW-DIST'), true);
check('search no match', ConfigSchema::matches('pvp', ['title' => 'Player vs player', 'help' => 'x'], 'motd'), false);
check('secrets are hidden', ConfigSchema::isHidden('server', 'management-server-secret'), true);
check('rcon password is write-only', ConfigSchema::entries('server')['rcon.password']['type'], 'password');
check('gamerule raw int', GameRules::parseRaw('Gamerule minecraft:random_tick_speed is currently set to: 3'), '3');
check('gamerule raw bool', GameRules::parseRaw('Gamerule keepInventory is currently set to: true'), 'true');
check('gamerule raw unknown', GameRules::parseRaw('Unknown or incomplete command'), null);

// --- Server replies ---
check('list', PlayerService::parseList('There are 2 of a max of 20 players online: Doobie, kelp_lord'), ['Doobie', 'kelp_lord']);
check('list empty', PlayerService::parseList('There are 0 of a max of 20 players online: '), []);
check('list garbage', PlayerService::parseList('Unknown command'), []);
check('position', PlayerService::parsePosition('Doobie has the following entity data: [212.5d, 71.0d, -140.3d]'), [212, 71, -141]);
check('position missing', PlayerService::parsePosition('No entity was found'), null);
check('dimension', PlayerService::parseDimension('Doobie has the following entity data: "minecraft:the_nether"'), 'the_nether');
check('gamerule old reply', GameRules::parseValue('Gamerule keepInventory is currently set to: false'), false);
check('gamerule new reply', GameRules::parseValue('Gamerule minecraft:keep_inventory is currently set to: true'), true);
check('gamerule unknown', GameRules::parseValue('Incorrect argument for command'), null);

// --- squaremap ---
$squaremapConfig = <<<'YAML'
config-version: 2
settings:
  web-address: http://localhost:8080
  web-directory:
    path: web
  internal-webserver:
    # Serve the map from the plugin itself
    enabled: true
    bind: 0.0.0.0
    port: 8123 # changed for Pelican
  ui:
    port: 1
world-settings:
  default:
    internal-webserver:
      port: 9999
YAML;
check('squaremap config', Squaremap::parseConfig($squaremapConfig), ['enabled' => true, 'port' => 8123]);
check('squaremap config found on Fabric', in_array('squaremap/config.yml', Squaremap::CONFIG_PATHS, true), true);
check('squaremap web off', Squaremap::parseConfig("settings:\n  internal-webserver:\n    enabled: false\n"), ['enabled' => false, 'port' => 8080]);
check('squaremap defaults', Squaremap::parseConfig("settings:\n  ui:\n    port: 1\n"), ['enabled' => true, 'port' => 8080]);
check('squaremap port outside block ignored', Squaremap::parseConfig("settings:\n  internal-webserver:\n    enabled: true\n  port: 1234\n")['port'], 8080);
check('squaremap worlds', Squaremap::worlds(['worlds' => [
    ['name' => 'minecraft_the_nether', 'display_name' => 'The <b>Nether</b>', 'type' => 'nether', 'order' => 1],
    ['name' => 'minecraft_overworld', 'display_name' => 'World', 'type' => 'normal', 'order' => 0],
    ['name' => '../etc', 'type' => 'normal'],
]]), [
    ['name' => 'minecraft_overworld', 'label' => 'World', 'type' => 'overworld'],
    ['name' => 'minecraft_the_nether', 'label' => 'The Nether', 'type' => 'nether'],
]);
check('squaremap world settings', Squaremap::worldSettings(['zoom' => ['max' => 3, 'def' => 1, 'extra' => 2], 'spawn' => ['x' => -40, 'z' => 212]]), ['max' => 3, 'def' => 1, 'extra' => 2, 'spawn' => ['x' => -40, 'z' => 212]]);
check('squaremap world settings missing', Squaremap::worldSettings([])['max'], 3);
check('squaremap players', Squaremap::players(['players' => [
    ['name' => 'Doobie', 'uuid' => 'abc', 'world' => 'minecraft_overworld', 'x' => 212, 'y' => 71, 'z' => -141, 'yaw' => 90, 'health' => 20],
    ['name' => 'hidden', 'world' => 'minecraft_overworld'],
], 'max' => 20]), [
    ['name' => 'Doobie', 'world' => 'minecraft_overworld', 'x' => 212, 'y' => 71, 'z' => -141, 'yaw' => 90, 'health' => 20],
]);
check('tile path allowed', Squaremap::isProxiedPath('tiles/minecraft_overworld/3/-1_0.png'), true);
check('world settings allowed', Squaremap::isProxiedPath('tiles/minecraft_overworld/settings.json'), true);
check('players.json not proxied', Squaremap::isProxiedPath('tiles/players.json'), false);
check('traversal rejected', Squaremap::isProxiedPath('tiles/../../etc/3/0_0.png'), false);
check('other files rejected', Squaremap::isProxiedPath('index.html'), false);

// --- RCON packets ---
$packet = RconClient::encode(7, RconClient::TYPE_COMMAND, 'list');
check('packet length field', unpack('V', $packet)[1], 4 + 4 + 4 + 2);
check('packet roundtrip', RconClient::decode(substr($packet, 4)), ['id' => 7, 'type' => 2, 'body' => 'list']);
check('auth failure id is -1', RconClient::decode(pack('VV', 0xFFFFFFFF, 2) . "\0\0")['id'], -1);

// --- RCON against a fake server ---
$port = random_int(40000, 50000);
$proc = proc_open([PHP_BINARY, __DIR__ . '/fake-rcon-server.php', (string) $port, 'hunter2'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
check('fake server started', trim((string) fgets($pipes[1])), 'ready');

$client = new RconClient('127.0.0.1', $port, 'hunter2');
$client->connect();
check('rcon list', $client->command('list'), 'There are 2 of a max of 20 players online: Doobie, kelp_lord');
check('rcon echo', $client->command('time set day'), 'ok: time set day');
check('rcon split reply', strlen($client->command('long')), 5000);
$client->close();

throws('rcon wrong password', function () use ($port) {
    (new RconClient('127.0.0.1', $port, 'wrong'))->connect();
}, RconException::class);
throws('rcon nothing listening', function () {
    (new RconClient('127.0.0.1', 1, 'x', 0.5))->connect();
}, RconException::class);

proc_terminate($proc);

echo $failures ? "\n$failures of $count checks failed\n" : "All $count checks passed\n";
exit($failures ? 1 : 0);
