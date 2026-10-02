<?php

namespace Headdetect\Overseer;

use Filament\Contracts\Plugin;
use Filament\Panel;

class OverseerPlugin implements Plugin
{
    public function getId(): string
    {
        return 'overseer';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverClusters(plugin_path($this->getId(), "src/Filament/$id/Clusters"), "Headdetect\\Overseer\\Filament\\$id\\Clusters");
        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "Headdetect\\Overseer\\Filament\\$id\\Pages");
    }

    public function boot(Panel $panel): void {}
}
