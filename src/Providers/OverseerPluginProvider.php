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
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Headdetect\Overseer\Filament\Server\Pages;
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

        // Most of Overseer needs RCON, so every page warns when it's off or not answering.
        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_SUB_NAVIGATION_TOP_AFTER,
            fn () => view('overseer::rcon-warning'),
            scopes: [Pages\Overview::class, Pages\Players::class, Pages\Config::class, Pages\Tools::class],
        );

        FilamentView::registerRenderHook(PanelsRenderHook::PAGE_START, fn () => view('overseer::config-styles'), scopes: [Pages\Config::class]);

        // Refresh controls on the tab row. Not on Config, where a refresh would drop unsaved edits.
        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_SUB_NAVIGATION_TOP_BEFORE,
            fn () => view('overseer::refresh-control'),
            scopes: [Pages\Overview::class, Pages\Players::class, Pages\Tools::class],
        );

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(LiftExpiredBans::class)->everyMinute()->withoutOverlapping();
        });
    }
}
