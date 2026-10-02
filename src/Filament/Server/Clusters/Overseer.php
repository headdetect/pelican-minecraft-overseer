<?php

namespace Headdetect\Overseer\Filament\Server\Clusters;

use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;

/**
 * One "Overseer" item at the top of the server sidebar. Its pages show as tabs
 * across the top of the view. Filament hides the item when the user can open
 * none of the pages, and sends them to the first one they can open.
 */
class Overseer extends Cluster
{
    protected static string|\BackedEnum|null $navigationIcon = 'tabler-eye';

    protected static ?string $slug = 'overseer';

    // Pelican's Console is 1, so 0 puts Overseer first.
    protected static ?int $navigationSort = 0;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    public static function getNavigationLabel(): string
    {
        return trans('overseer::overseer.cluster');
    }

    public static function getClusterBreadcrumb(): string
    {
        return static::getNavigationLabel();
    }
}
