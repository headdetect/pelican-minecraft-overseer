<?php

// Runs one RCON command against the dev server. script/rcon calls this.

use App\Models\Server;
use Headdetect\Overseer\Services\Rcon\RconConnector;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$server = Server::query()->where('name', 'dev')->firstOrFail();
$client = app(RconConnector::class)->connect($server);
echo $client->command($argv[1]), "\n";
$client->close();
