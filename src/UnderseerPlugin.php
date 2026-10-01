<?php

namespace Headdetect\Underseer;

use Filament\Contracts\Plugin;
use Filament\Panel;

class UnderseerPlugin implements Plugin
{
    public function getId(): string
    {
        return 'underseer';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "Headdetect\\Underseer\\Filament\\$id\\Pages");
    }

    public function boot(Panel $panel): void {}
}
