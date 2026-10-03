<?php

namespace Headdetect\Overseer\Filament\Server\Clusters;

use App\Enums\ContainerStatus;
use App\Models\Server;
use Filament\Clusters\Cluster;
use Filament\Facades\Filament;
use Filament\Pages\Enums\SubNavigationPosition;
use Headdetect\Overseer\Services\ConsoleService;
use Headdetect\Overseer\Services\PlayerService;
use Headdetect\Overseer\Support\Permission;

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

    /** A user with none of Overseer's permissions gets a 403 instead of an empty page. */
    public static function canAccess(): bool
    {
        return static::canAccessClusteredComponents() && parent::canAccess();
    }

    public static function getNavigationLabel(): string
    {
        return trans('overseer::overseer.cluster');
    }

    /**
     * How many players are online, from "list" over RCON. Cached briefly,
     * because the sidebar is on every server page. Hidden when nobody is
     * online, RCON can't answer, or the user can't see players.
     */
    public static function getNavigationBadge(): ?string
    {
        /** @var ?Server $server */
        $server = Filament::getTenant();
        if (!$server instanceof Server
            || !(Permission::allows(Permission::MAP_VIEW, $server) || Permission::allows(Permission::PLAYERS_VIEW, $server))) {
            return null;
        }

        $count = cache()->remember("overseer:online-count:$server->uuid", now()->addSeconds(30), function () use ($server) {
            if ($server->retrieveStatus() !== ContainerStatus::Running) {
                return 0;
            }

            $reply = app(ConsoleService::class)->query($server, 'list');

            return $reply === null ? 0 : count(PlayerService::parseList($reply));
        });

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'success';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return trans('overseer::overseer.players_online');
    }

    public static function getClusterBreadcrumb(): string
    {
        return static::getNavigationLabel();
    }
}
