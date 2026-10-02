<?php

namespace Headdetect\Overseer\Console\Commands;

use App\Enums\ContainerStatus;
use Exception;
use Headdetect\Overseer\Models\TimedBan;
use Headdetect\Overseer\Services\ConsoleService;
use Illuminate\Console\Command;

class LiftExpiredBans extends Command
{
    protected $signature = 'overseer:lift-expired-bans';

    protected $description = 'Unban players whose Overseer timed ban has run out.';

    public function handle(): int
    {
        TimedBan::expired()->with('server')->each(function (TimedBan $ban) {
            // Offline servers keep the ban until they are running again; pardon needs a live server.
            if ($ban->server->retrieveStatus() !== ContainerStatus::Running) {
                return;
            }

            try {
                app(ConsoleService::class)->run($ban->server, 'unban', "pardon $ban->player", $ban->player);
                $ban->update(['lifted_at' => now()]);
                $this->info("Unbanned $ban->player on {$ban->server->name}.");
            } catch (Exception $exception) {
                report($exception);
                $this->warn("Couldn't unban $ban->player on {$ban->server->name}: {$exception->getMessage()}");
            }
        });

        return self::SUCCESS;
    }
}
