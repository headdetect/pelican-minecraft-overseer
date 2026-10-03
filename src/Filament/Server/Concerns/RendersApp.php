<?php

namespace Headdetect\Overseer\Filament\Server\Concerns;

use App\Models\Server;
use Filament\Facades\Filament;
use Headdetect\Overseer\Support\AppBoot;

/**
 * The Overseer pages are one React app. Each page renders the same root with
 * its own tab selected, so a reload or a shared link opens the right tab.
 * The app switches tabs in the browser without loading the page again.
 */
trait RendersApp
{
    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return [
            'boot' => AppBoot::payload($server, static::$slug),
            'script' => AppBoot::assetUrl('overseer.js'),
            'style' => AppBoot::assetUrl('overseer.css'),
        ];
    }
}
