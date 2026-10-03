<?php

namespace Headdetect\Overseer;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TextInput;
use Filament\Panel;

class OverseerPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

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

    /** The Discord application, shared by every server on the panel. Shown under Admin > Plugins. */
    public function getSettingsForm(): array
    {
        return [
            TextInput::make('discord_app_id')
                ->label(trans('overseer::overseer.discord.settings.app_id'))
                ->helperText(trans('overseer::overseer.discord.settings.app_id_help'))
                ->regex('/^\d{17,20}$/'),
            TextInput::make('discord_public_key')
                ->label(trans('overseer::overseer.discord.settings.public_key'))
                ->regex('/^[0-9a-f]{64}$/i'),
            TextInput::make('discord_bot_token')
                ->label(trans('overseer::overseer.discord.settings.bot_token'))
                ->helperText(trans('overseer::overseer.discord.settings.bot_token_help'))
                ->password()
                ->revealable(),
        ];
    }

    public function getSettingsFormData(): array
    {
        return [
            'discord_app_id' => config('overseer.discord.app_id'),
            'discord_public_key' => config('overseer.discord.public_key'),
            // Never sent back to the browser. An empty field on save keeps the current token.
            'discord_bot_token' => null,
        ];
    }

    public function saveSettings(array $data): void
    {
        $values = [
            'OVERSEER_DISCORD_APP_ID' => trim((string) ($data['discord_app_id'] ?? '')),
            'OVERSEER_DISCORD_PUBLIC_KEY' => strtolower(trim((string) ($data['discord_public_key'] ?? ''))),
        ];

        if (filled($data['discord_bot_token'] ?? null)) {
            $values['OVERSEER_DISCORD_BOT_TOKEN'] = trim($data['discord_bot_token']);
        }

        $this->writeToEnvironment($values);
    }
}
