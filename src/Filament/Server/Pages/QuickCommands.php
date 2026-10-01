<?php

namespace Headdetect\Underseer\Filament\Server\Pages;

use App\Enums\ContainerStatus;
use App\Filament\Server\Pages\ServerFormPage;
use App\Models\Server;
use Exception;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Headdetect\Underseer\Models\AuditEntry;
use Headdetect\Underseer\Services\ConsoleService;
use Headdetect\Underseer\Services\GameRules;
use Headdetect\Underseer\Support\CommandInput;
use Headdetect\Underseer\Support\Permission;

class QuickCommands extends ServerFormPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'tabler-bolt';

    protected static ?string $slug = 'underseer/commands';

    protected static ?int $navigationSort = 31;

    /** Whether game rule values could be read from the server. */
    public bool $rulesKnown = false;

    public static function canAccess(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return (Permission::allows(Permission::COMMANDS_WORLD, $server) || Permission::allows(Permission::COMMANDS_OPS, $server))
            && parent::canAccess();
    }

    public static function getNavigationLabel(): string
    {
        return trans('underseer::underseer.commands.title');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    protected function fillForm(): void
    {
        $rules = [];
        if ($this->getRecord()->retrieveStatus() === ContainerStatus::Running) {
            $rules = app(GameRules::class)->values($this->getRecord());
        }

        $this->rulesKnown = $rules !== [] && !in_array(null, $rules, true);

        $this->form->fill(['rules' => array_map(fn ($value) => $value ?? false, $rules)]);
    }

    public function form(Schema $schema): Schema
    {
        $world = Permission::allows(Permission::COMMANDS_WORLD, $this->getRecord());
        $ops = Permission::allows(Permission::COMMANDS_OPS, $this->getRecord());

        return parent::form($schema)->components([
            Grid::make(['default' => 1, 'lg' => 2])->columnSpanFull()->schema([
                Section::make(trans('underseer::underseer.commands.time.title'))
                    ->description(trans('underseer::underseer.commands.time.help'))
                    ->icon('tabler-sun')
                    ->visible($world)
                    ->schema([Actions::make([
                        $this->commandButton('sunrise', 'time', 'time set 0', 'tabler-sunrise'),
                        $this->commandButton('noon', 'time', 'time set noon', 'tabler-sun'),
                        $this->commandButton('sunset', 'time', 'time set 12000', 'tabler-sunset'),
                        $this->commandButton('midnight', 'time', 'time set midnight', 'tabler-moon'),
                    ])]),
                Section::make(trans('underseer::underseer.commands.weather.title'))
                    ->description(trans('underseer::underseer.commands.weather.help'))
                    ->icon('tabler-cloud')
                    ->visible($world)
                    ->schema([Actions::make([
                        $this->commandButton('clear', 'weather', 'weather clear', 'tabler-sun'),
                        $this->commandButton('rain', 'weather', 'weather rain', 'tabler-cloud-rain'),
                        $this->commandButton('thunder', 'weather', 'weather thunder', 'tabler-cloud-storm'),
                    ])]),
                Section::make(trans('underseer::underseer.commands.difficulty.title'))
                    ->description(trans('underseer::underseer.commands.difficulty.help'))
                    ->icon('tabler-sword')
                    ->visible($world)
                    ->schema([Actions::make([
                        $this->commandButton('peaceful', 'difficulty', 'difficulty peaceful', 'tabler-mood-smile'),
                        $this->commandButton('easy', 'difficulty', 'difficulty easy', 'tabler-mood-empty'),
                        $this->commandButton('normal', 'difficulty', 'difficulty normal', 'tabler-mood-neutral'),
                        $this->commandButton('hard', 'difficulty', 'difficulty hard', 'tabler-skull'),
                    ])]),
                Section::make(trans('underseer::underseer.commands.rules.title'))
                    ->description(fn () => $this->rulesKnown
                        ? trans('underseer::underseer.commands.rules.help')
                        : trans('underseer::underseer.commands.rules.unknown'))
                    ->icon('tabler-adjustments')
                    ->visible($world)
                    ->schema(array_map(fn (string $key) => Toggle::make("rules.$key")
                        ->label(GameRules::LABELS[$key])
                        ->helperText(GameRules::RULES[$key][2])
                        ->live()
                        ->afterStateUpdated(fn (bool $state) => $this->setRule($key, $state)),
                        array_keys(GameRules::RULES))),
                Section::make(trans('underseer::underseer.commands.server.title'))
                    ->description(trans('underseer::underseer.commands.server.help'))
                    ->icon('tabler-server')
                    ->schema([Actions::make([
                        $this->commandButton('save', 'save', 'save-all', 'tabler-device-floppy')->visible($world),
                        $this->commandButton('whitelist_on', 'whitelist', 'whitelist on', 'tabler-shield-lock')->visible($ops),
                        $this->commandButton('whitelist_off', 'whitelist', 'whitelist off', 'tabler-shield-off')->visible($ops)->requiresConfirmation(),
                        Action::make('broadcast')
                            ->label(trans('underseer::underseer.commands.buttons.broadcast'))
                            ->icon('tabler-speakerphone')
                            ->visible($ops)
                            ->schema([Textarea::make('message')->label(trans('underseer::underseer.commands.server.message'))->required()->maxLength(200)->rows(2)])
                            ->action(fn (array $data) => $this->send('broadcast', 'say ' . CommandInput::text($data['message']))),
                        Action::make('custom')
                            ->label(trans('underseer::underseer.commands.buttons.custom'))
                            ->icon('tabler-terminal-2')
                            ->color('gray')
                            ->visible($ops)
                            ->schema([TextInput::make('command')
                                ->label(trans('underseer::underseer.commands.server.command'))
                                ->placeholder('gamerule players_sleeping_percentage 50')
                                ->prefix('/')
                                ->required()])
                            ->action(fn (array $data) => $this->send('custom', CommandInput::command($data['command']))),
                    ])]),
                Section::make(trans('underseer::underseer.commands.recent.title'))
                    ->description(trans('underseer::underseer.commands.recent.help'))
                    ->icon('tabler-history')
                    ->schema([
                        TextEntry::make('recent')
                            ->hiddenLabel()
                            ->state(fn () => $this->recentActions() ?: [trans('underseer::underseer.commands.recent.empty')])
                            ->listWithLineBreaks(),
                    ]),
            ]),
        ]);
    }

    private function commandButton(string $name, string $action, string $command, string $icon): Action
    {
        return Action::make($name)
            ->label(trans("underseer::underseer.commands.buttons.$name"))
            ->tooltip('/' . $command)
            ->icon($icon)
            ->color('gray')
            ->action(fn () => $this->send($action, $command));
    }

    private function setRule(string $key, bool $value): void
    {
        try {
            app(GameRules::class)->set($this->getRecord(), $key, $value);

            Notification::make()
                ->title(trans('underseer::underseer.commands.rules.changed', ['rule' => GameRules::LABELS[$key], 'state' => $value ? 'on' : 'off']))
                ->success()
                ->send();
        } catch (Exception $exception) {
            Notification::make()->title(trans('underseer::underseer.commands.failed'))->body($exception->getMessage())->danger()->send();
        }
    }

    private function send(string $action, string $command): void
    {
        try {
            $reply = app(ConsoleService::class)->run($this->getRecord(), $action, $command);

            Notification::make()
                ->title(trans('underseer::underseer.commands.sent', ['command' => '/' . $command]))
                ->body($reply ?: null)
                ->success()
                ->send();
        } catch (Exception $exception) {
            Notification::make()->title(trans('underseer::underseer.commands.failed'))->body($exception->getMessage())->danger()->send();
        }
    }

    /** @return string[] */
    private function recentActions(): array
    {
        return AuditEntry::query()
            ->with('user')
            ->where('server_id', $this->getRecord()->id)
            ->latest('created_at')
            ->limit(config('underseer.recent_actions', 10))
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
}
