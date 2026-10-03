<?php

namespace Headdetect\Overseer\Filament\Server\Pages;

use App\Models\Server;
use App\Traits\Filament\BlockAccessInConflict;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Headdetect\Overseer\Filament\Server\Clusters\Overseer;
use Headdetect\Overseer\Filament\Server\Concerns\RendersApp;
use Headdetect\Overseer\Support\Permission;

class Config extends Page
{
    use BlockAccessInConflict;
    use RendersApp;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-settings-2';

    protected static ?string $slug = 'config';

    protected static ?string $cluster = Overseer::class;

    protected static ?int $navigationSort = 4;

    protected string $view = 'overseer::app';

    public static function canAccess(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return (Permission::allows(Permission::CONFIG_VIEW, $server) || Permission::allows(Permission::CONFIG_EDIT, $server))
            && parent::canAccess();
    }

    public static function getNavigationLabel(): string
    {
        return trans('overseer::overseer.config.title');
    }
}
