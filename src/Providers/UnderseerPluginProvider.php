<?php

namespace Headdetect\Underseer\Providers;

use App\Models\Subuser;
use Headdetect\Underseer\Console\Commands\LiftExpiredBans;
use Headdetect\Underseer\Services\ConsoleService;
use Headdetect\Underseer\Services\GameRules;
use Headdetect\Underseer\Services\PlayerService;
use Headdetect\Underseer\Services\Rcon\RconConnector;
use Headdetect\Underseer\Support\Permission;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class UnderseerPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        // One RCON connection per request, shared by every service that needs it.
        $this->app->scoped(RconConnector::class);
        $this->app->scoped(ConsoleService::class);
        $this->app->scoped(PlayerService::class);
        $this->app->scoped(GameRules::class);

        Subuser::registerCustomPermissions(
            name: Permission::GROUP,
            permissions: Permission::names(),
            translationPrefix: 'underseer::permissions',
            icon: 'tabler-eye',
        );
    }

    public function boot(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(LiftExpiredBans::class)->everyMinute()->withoutOverlapping();
        });
    }
}
