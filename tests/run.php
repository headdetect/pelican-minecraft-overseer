<?php

// Unit tests for the parts of Underseer that don't need a running panel.
// Run with: php tests/run.php

spl_autoload_register(function (string $class) {
    $prefix = 'Headdetect\\Underseer\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use Headdetect\Underseer\Services\GameRules;
use Headdetect\Underseer\Services\PlayerService;
use Headdetect\Underseer\Services\Rcon\RconClient;
use Headdetect\Underseer\Services\Rcon\RconException;
use Headdetect\Underseer\Support\CommandInput;
use Headdetect\Underseer\Support\Properties;

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
