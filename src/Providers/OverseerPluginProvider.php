<?php

namespace Headdetect\Overseer\Providers;

use App\Models\Subuser;
use Headdetect\Overseer\Console\Commands\LiftExpiredBans;
use Headdetect\Overseer\Services\ConsoleService;
use Headdetect\Overseer\Services\GameRules;
use Headdetect\Overseer\Services\Map\MapService;
use Headdetect\Overseer\Services\PlayerService;
use Headdetect\Overseer\Services\Rcon\RconConnector;
use Headdetect\Overseer\Support\Permission;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class OverseerPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        // One RCON connection per request, shared by every service that needs it.
        $this->app->scoped(RconConnector::class);
        $this->app->scoped(ConsoleService::class);
        $this->app->scoped(PlayerService::class);
        $this->app->scoped(GameRules::class);
        $this->app->scoped(MapService::class);

        Subuser::registerCustomPermissions(
            name: Permission::GROUP,
            permissions: Permission::names(),
            translationPrefix: 'overseer::permissions',
            icon: 'tabler-eye',
        );
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(plugin_path('overseer', 'routes/web.php'));

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(LiftExpiredBans::class)->everyMinute()->withoutOverlapping();
        });
    }
}
