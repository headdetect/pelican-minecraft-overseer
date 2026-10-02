<?php

namespace Headdetect\Overseer\Filament\Server\Concerns;

use App\Models\Server;
use Exception;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Headdetect\Overseer\Models\AuditEntry;
use Headdetect\Overseer\Services\ConsoleService;
use Headdetect\Overseer\Support\CommandInput;
use Headdetect\Overseer\Support\Permission;

/**
 * The one-click commands on the Overview: time of day, weather, and the server
 * panel, plus the list of recent actions. Time and weather need the
 * commands-world permission. Whitelist, broadcast and any command need
 * commands-ops.
 */
trait RunsQuickCommands
{
    abstract protected function server(): Server;

    public function canRunWorldCommands(): bool
    {
        return Permission::allows(Permission::COMMANDS_WORLD, $this->server());
    }

    public function canRunOpsCommands(): bool
    {
        return Permission::allows(Permission::COMMANDS_OPS, $this->server());
    }

    public function sunriseAction(): Action
    {
        return $this->commandButton('sunrise', 'time', 'time set 0', 'tabler-sunrise', world: true);
    }

    public function noonAction(): Action
    {
        return $this->commandButton('noon', 'time', 'time set noon', 'tabler-sun', world: true);
    }

    public function sunsetAction(): Action
    {
        return $this->commandButton('sunset', 'time', 'time set 12000', 'tabler-sunset', world: true);
    }

    public function midnightAction(): Action
    {
        return $this->commandButton('midnight', 'time', 'time set midnight', 'tabler-moon', world: true);
    }

    public function clearAction(): Action
    {
        return $this->commandButton('clear', 'weather', 'weather clear', 'tabler-sun', world: true);
    }

    public function rainAction(): Action
    {
        return $this->commandButton('rain', 'weather', 'weather rain', 'tabler-cloud-rain', world: true);
    }

    public function thunderAction(): Action
    {
        return $this->commandButton('thunder', 'weather', 'weather thunder', 'tabler-cloud-storm', world: true);
    }

    public function saveAction(): Action
    {
        return $this->commandButton('save', 'save', 'save-all', 'tabler-device-floppy', world: true);
    }

    public function whitelistOnAction(): Action
    {
        return $this->commandButton('whitelist_on', 'whitelist', 'whitelist on', 'tabler-shield-lock', world: false, name: 'whitelistOn');
    }

    public function whitelistOffAction(): Action
    {
        return $this->commandButton('whitelist_off', 'whitelist', 'whitelist off', 'tabler-shield-off', world: false, name: 'whitelistOff')
            ->requiresConfirmation();
    }

    public function broadcastAction(): Action
    {
        return Action::make('broadcast')
            ->button()
            ->color('gray')
            ->label(trans('overseer::overseer.commands.buttons.broadcast'))
            ->icon('tabler-speakerphone')
            ->visible(fn () => $this->canRunOpsCommands())
            ->schema([Textarea::make('message')->label(trans('overseer::overseer.commands.server.message'))->required()->maxLength(200)->rows(2)])
            ->action(fn (array $data) => $this->sendCommand('broadcast', 'say ' . CommandInput::text($data['message'])));
    }

    public function customAction(): Action
    {
        return Action::make('custom')
            ->button()
            ->color('gray')
            ->label(trans('overseer::overseer.commands.buttons.custom'))
            ->icon('tabler-terminal-2')
            ->visible(fn () => $this->canRunOpsCommands())
            ->schema([TextInput::make('command')
                ->label(trans('overseer::overseer.commands.server.command'))
                ->placeholder('gamerule players_sleeping_percentage 50')
                ->prefix('/')
                ->required()])
            ->action(fn (array $data) => $this->sendCommand('custom', CommandInput::command($data['command'])));
    }

    /** @return string[] */
    public function recentActions(): array
    {
        return AuditEntry::query()
            ->with('user')
            ->where('server_id', $this->server()->id)
            ->latest('created_at')
            ->limit(config('overseer.recent_actions', 10))
            ->get()
            ->map(fn (AuditEntry $entry) => sprintf(
                '%s · %s · /%s%s',
                $entry->created_at->diffForHumans(short: true),
                $entry->user->username ?? 'system',
                $entry->command,
                $entry->response ? ' → ' . str($entry->response)->limit(80) : '',
            ))
            ->all();
    }

    private function commandButton(string $label, string $action, string $command, string $icon, bool $world, ?string $name = null): Action
    {
        return Action::make($name ?? $label)
            ->button()
            ->color('gray')
            ->label(trans("overseer::overseer.commands.buttons.$label"))
            ->tooltip('/' . $command)
            ->icon($icon)
            ->visible(fn () => $world ? $this->canRunWorldCommands() : $this->canRunOpsCommands())
            ->action(fn () => $this->sendCommand($action, $command));
    }

    private function sendCommand(string $action, string $command): void
    {
        try {
            $reply = app(ConsoleService::class)->run($this->server(), $action, $command);

            Notification::make()
                ->title(trans('overseer::overseer.commands.sent', ['command' => '/' . $command]))
                ->body($reply ?: null)
                ->success()
                ->send();
        } catch (Exception $exception) {
            Notification::make()->title(trans('overseer::overseer.commands.failed'))->body($exception->getMessage())->danger()->send();
        }
    }
}
