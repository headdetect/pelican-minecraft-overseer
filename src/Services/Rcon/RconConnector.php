<?php

namespace Headdetect\Underseer\Services\Rcon;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use Headdetect\Underseer\Support\Properties;
use Headdetect\Underseer\Support\ServerAddress;

/**
 * Builds an RCON connection for a server from its own server.properties.
 */
class RconConnector
{
    /** @var array<string, array<string, string>> */
    private array $properties = [];

    /** Whether server.properties has RCON turned on with a password. */
    public function isConfigured(Server $server): bool
    {
        $properties = $this->properties($server);

        return Properties::bool($properties, 'enable-rcon') && ($properties['rcon.password'] ?? '') !== '';
    }

    public function connect(Server $server): RconClient
    {
        if (!$this->isConfigured($server)) {
            throw new RconException('RCON is off. Set enable-rcon=true and an rcon.password in server.properties.');
        }

        $properties = $this->properties($server);

        $client = new RconClient(
            ServerAddress::host($server, config('underseer.rcon.host')),
            (int) ($properties['rcon.port'] ?? 25575),
            $properties['rcon.password'],
            (float) config('underseer.rcon.timeout', 2.0),
        );
        $client->connect();

        return $client;
    }

    /** @return array<string, string> */
    public function properties(Server $server): array
    {
        if (!isset($this->properties[$server->uuid])) {
            try {
                $contents = (new DaemonFileRepository())->setServer($server)->getContent('server.properties');
                $this->properties[$server->uuid] = Properties::parse($contents);
            } catch (Exception $exception) {
                report($exception);
                $this->properties[$server->uuid] = [];
            }
        }

        return $this->properties[$server->uuid];
    }
}
