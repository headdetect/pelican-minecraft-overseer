<?php

namespace Headdetect\Overseer\Services;

use App\Enums\ContainerStatus;
use App\Facades\Activity;
use App\Models\Server;
use Exception;
use Headdetect\Overseer\Models\AuditEntry;
use Headdetect\Overseer\Services\Rcon\RconClient;
use Headdetect\Overseer\Services\Rcon\RconConnector;
use Headdetect\Overseer\Services\Rcon\RconException;
use Headdetect\Overseer\Support\CommandInput;
use RuntimeException;

/**
 * The one way Overseer runs a command on a server. Every call is checked,
 * written to the audit table and to Pelican's activity log.
 *
 * Commands go over RCON when it is set up, so the server's reply can be shown.
 * Otherwise they go through Wings like a command typed in the console, and the
 * reply only appears in the console.
 */
class ConsoleService
{
    private ?RconClient $rcon = null;

    private bool $rconFailed = false;

    public function __construct(private readonly RconConnector $connector) {}

    /**
     * @param  string  $action  short name for the log, e.g. "kick" or "time"
     * @return ?string the server's reply, or null when it went through Wings
     */
    public function run(Server $server, string $action, string $command, ?string $target = null): ?string
    {
        if ($server->retrieveStatus() !== ContainerStatus::Running) {
            throw new RuntimeException('The server is not running. Start it to use this.');
        }

        $command = CommandInput::command($command);
        $response = null;

        if ($client = $this->rcon($server)) {
            try {
                $response = CommandInput::stripFormatting(trim($client->command($command)));
            } catch (RconException $exception) {
                report($exception);
                $this->rcon = null;
                $this->rconFailed = true;
            }
        }

        if ($response === null && !$this->rcon) {
            try {
                $server->send($command);
            } catch (Exception $exception) {
                throw new RuntimeException('The server did not accept the command: ' . $exception->getMessage(), previous: $exception);
            }
        }

        AuditEntry::create([
            'server_id' => $server->id,
            'user_id' => user()?->id,
            'action' => $action,
            'target' => $target,
            'command' => $command,
            'response' => $response,
        ]);

        Activity::event("server:overseer.$action")
            ->property(['command' => $command, 'target' => $target])
            ->log();

        return $response;
    }

    /** Runs a read-only command over RCON. Returns null when RCON isn't available. Not logged. */
    public function query(Server $server, string $command): ?string
    {
        if (!$client = $this->rcon($server)) {
            return null;
        }

        try {
            return CommandInput::stripFormatting(trim($client->command($command)));
        } catch (RconException $exception) {
            report($exception);
            $this->rcon = null;
            $this->rconFailed = true;

            return null;
        }
    }

    public function hasRcon(Server $server): bool
    {
        return $this->rcon($server) !== null;
    }

    private function rcon(Server $server): ?RconClient
    {
        if ($this->rcon || $this->rconFailed) {
            return $this->rcon;
        }

        if (!$this->connector->isConfigured($server)) {
            return null;
        }

        try {
            $this->rcon = $this->connector->connect($server);
        } catch (RconException $exception) {
            report($exception);
            $this->rconFailed = true;
        }

        return $this->rcon;
    }
}
