<?php

namespace Headdetect\Overseer\Filament\Server\Pages;

use App\Models\Server;
use App\Traits\Filament\BlockAccessInConflict;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Headdetect\Overseer\Filament\Server\Clusters\Overseer;
use Headdetect\Overseer\Filament\Server\Concerns\RendersApp;
use Headdetect\Overseer\Support\Permission;

class Overview extends Page
{
    use BlockAccessInConflict;
    use RendersApp;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-layout-dashboard';

    protected static ?string $slug = 'overview';

    protected static ?string $cluster = Overseer::class;

    protected static ?int $navigationSort = 1;

    protected string $view = 'overseer::app';

    public static function canAccess(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        // The map and stats need map-view. The command cards have their own permissions.
        return (Permission::allows(Permission::MAP_VIEW, $server)
            || Permission::allows(Permission::COMMANDS_WORLD, $server)
            || Permission::allows(Permission::COMMANDS_OPS, $server))
            && parent::canAccess();
    }

    public static function getNavigationLabel(): string
    {
        return trans('overseer::overseer.overview.title');
    }
}
