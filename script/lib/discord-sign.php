<?php

// Sends a signed Discord interaction to the dev panel. script/discord-sign calls this.
//
// The key pair comes from a fixed seed, so the public key stays the same.
// Discord can't reach localhost, so this stands in for it.

use App\Models\Server;
use Headdetect\Overseer\Models\DiscordSetting;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$keys = sodium_crypto_sign_seed_keypair(str_repeat("\x2a", SODIUM_CRYPTO_SIGN_SEEDBYTES));
$publicKey = bin2hex(sodium_crypto_sign_publickey($keys));

[$command, $argument] = [$argv[1] ?? '', $argv[2] ?? ''];

if ($command === 'key') {
    echo "$publicKey\n";
    exit(0);
}

if ($command === 'link') {
    // Links the dev server to a made-up guild, as Register commands would.
    $server = Server::query()->where('name', 'dev')->firstOrFail();
    DiscordSetting::for($server)->fill(['guild_id' => '100000000000000001', 'guild_name' => 'Dev Discord'])->save();
    echo "Linked the dev server to guild 100000000000000001\n";
    exit(0);
}

if ($command === 'ping') {
    $body = ['type' => 1];
} elseif (in_array($command, ['status', 'info', 'whitelist'], true)) {
    $body = [
        'type' => 2,
        // "local" makes the panel log the reply instead of sending it to Discord.
        'token' => 'local',
        'guild_id' => '100000000000000001',
        'member' => ['user' => ['id' => '200000000000000002', 'username' => 'dev', 'global_name' => 'Dev']],
        'data' => array_filter([
            'name' => $command,
            'options' => $command === 'whitelist' ? [['type' => 3, 'name' => 'name', 'value' => $argument]] : null,
        ]),
    ];
} else {
    fwrite(STDERR, "usage: script/discord-sign key | link | ping | status | info | whitelist <name>\n");
    exit(2);
}

if (config('overseer.discord.public_key') !== $publicKey) {
    fwrite(STDERR, "The panel's OVERSEER_DISCORD_PUBLIC_KEY isn't the dev key, so it will answer 401. Set it to: $publicKey\n");
}

$json = json_encode($body);
$timestamp = (string) time();
$signature = bin2hex(sodium_crypto_sign_detached($timestamp . $json, sodium_crypto_sign_secretkey($keys)));

$response = Illuminate\Support\Facades\Http::withHeaders([
    'X-Signature-Ed25519' => $signature,
    'X-Signature-Timestamp' => $timestamp,
])->withBody($json, 'application/json')->post(rtrim(config('app.url'), '/') . '/overseer/discord/interactions');

echo $response->status(), ' ', $response->body(), "\n";
if (($body['type'] ?? null) === 2) {
    echo "The reply after \"thinking\" goes to the Laravel log as \"Discord reply\".\n";
}
