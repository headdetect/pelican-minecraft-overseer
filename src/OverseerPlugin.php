<?php

namespace Headdetect\Overseer;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Actions\Action;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TextInput;
use Filament\Panel;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;

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

    /**
     * The Discord application, shared by every server on the panel. Shown under
     * Admin > Plugins. The help under each field says where it is in Discord's
     * Developer Portal, in the order you fill them in.
     */
    public function getSettingsForm(): array
    {
        $text = fn (string $key) => trans("overseer::overseer.discord.settings.$key");

        return [
            Section::make($text('from_discord'))
                ->description($text('from_discord_help'))
                ->schema([
                    Actions::make([
                        Action::make('discord_portal')
                            ->label($text('portal'))
                            ->icon('tabler-external-link')
                            ->color('gray')
                            ->url('https://discord.com/developers/applications', shouldOpenInNewTab: true),
                    ]),
                    TextInput::make('discord_app_id')
                        ->label($text('app_id'))
                        ->helperText($text('app_id_help'))
                        ->regex('/^\d{17,20}$/'),
                    TextInput::make('discord_public_key')
                        ->label($text('public_key'))
                        ->helperText($text('public_key_help'))
                        ->regex('/^[0-9a-f]{64}$/i'),
                    TextInput::make('discord_bot_token')
                        ->label($text('bot_token'))
                        ->helperText($text('bot_token_help'))
                        ->password()
                        ->revealable(),
                ]),
            Section::make($text('into_discord'))
                ->description($text('into_discord_help'))
                ->schema([
                    TextInput::make('discord_interactions_url')
                        ->label($text('interactions_url'))
                        ->helperText($text('interactions_url_help'))
                        ->readOnly()
                        ->dehydrated(false)
                        ->copyable(copyMessage: $text('copied')),
                ]),
        ];
    }

    public function getSettingsFormData(): array
    {
        return [
            'discord_app_id' => config('overseer.discord.app_id'),
            'discord_public_key' => config('overseer.discord.public_key'),
            // Never sent back to the browser. An empty field on save keeps the current token.
            'discord_bot_token' => null,
            'discord_interactions_url' => route('overseer.discord.interactions'),
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
