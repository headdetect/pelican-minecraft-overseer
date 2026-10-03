<?php

namespace Headdetect\Overseer\Filament\Server\Pages;

use App\Enums\ContainerStatus;
use App\Models\Server;
use App\Traits\Filament\BlockAccessInConflict;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Resources\Concerns\HasTabs;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Headdetect\Overseer\Filament\Server\Clusters\Overseer;
use Headdetect\Overseer\Models\TimedBan;
use Headdetect\Overseer\Services\ConsoleService;
use Headdetect\Overseer\Services\PlayerService;
use Headdetect\Overseer\Support\CommandInput;
use Headdetect\Overseer\Support\Permission;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;

class Players extends Page implements HasTable
{
    use BlockAccessInConflict;
    use HasTabs;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-users-group';

    protected static ?string $slug = 'players';

    protected static ?string $cluster = Overseer::class;

    protected static ?int $navigationSort = 2;

    #[Url(as: 'tab')]
    public ?string $activeTab = null;

    /** @var ?array<int, array<string, mixed>> null when RCON isn't available */
    public ?array $online = null;

    /** @var string[] */
    public array $ops = [];

    /** @var string[] */
    public array $whitelist = [];

    /** @var array<int, array<string, mixed>> */
    public array $banned = [];

    /** @var array<int, array<string, mixed>> everyone who has played, plus the whitelist */
    public array $roster = [];

    /** @var array<string, array<string, mixed>> game mode, level and dimension from player files, by name */
    public array $details = [];

    /** @var array<string, int> when each active timed ban ends, by player name */
    public array $timedBans = [];

    public static function canAccess(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return Permission::allows(Permission::PLAYERS_VIEW, $server) && parent::canAccess();
    }

    public static function getNavigationLabel(): string
    {
        return trans('overseer::overseer.players.title');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        // The Online and All known tabs became one All players tab.
        if (in_array($this->activeTab, ['online', 'known'], true) || ($this->activeTab !== null && !array_key_exists($this->activeTab, $this->getTabs()))) {
            $this->activeTab = null;
        }

        $this->loadPlayers();
        $this->loadDefaultActiveTab();
    }

    protected function loadPlayers(bool $fresh = false): void
    {
        $server = $this->server();
        $players = app(PlayerService::class);
        if ($fresh) {
            $players->forgetRoster($server);
        }

        $this->online = $server->retrieveStatus() === ContainerStatus::Running ? $players->online($server) : [];
        $this->ops = $players->ops($server);
        $this->whitelist = $players->whitelist($server);
        $this->banned = $players->banned($server);
        $this->roster = $players->roster($server, array_column($this->online ?? [], 'name'));
        $this->details = $players->details($server, $this->roster);
        $this->timedBans = TimedBan::active()->where('server_id', $server->id)->pluck('expires_at', 'player')->map(fn ($at) => $at->getTimestamp())->all();

        // The table caches its rows, so drop them to show what changed.
        $this->flushCachedTableRecords();
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('all')->label(trans('overseer::overseer.players.tabs.all'))->badge(fn () => count($this->roster)),
            'ops' => Tab::make('ops')->label(trans('overseer::overseer.players.tabs.ops'))->badge(fn () => count($this->ops)),
            'whitelist' => Tab::make('whitelist')->label(trans('overseer::overseer.players.tabs.whitelist'))->badge(fn () => count($this->whitelist)),
            'banned' => Tab::make('banned')->label(trans('overseer::overseer.players.tabs.banned'))->badge(fn () => count($this->banned)),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    protected function rows(): array
    {
        $positions = array_column($this->online ?? [], null, 'name');
        $roster = array_column($this->roster, null, 'name');
        // Online players' live values win over what their file had at the last save.
        $row = fn (string $name) => [
            ...($this->details[$name] ?? []),
            ...array_filter($positions[$name] ?? [], fn ($value) => $value !== null),
            'name' => $name,
            'is_online' => $roster[$name]['online'] ?? isset($positions[$name]),
            'last_seen' => $roster[$name]['last_seen'] ?? null,
        ];

        return match ($this->activeTab) {
            'ops' => array_map($row, $this->ops),
            'whitelist' => array_map($row, $this->whitelist),
            'banned' => $this->banned,
            default => array_map($row, array_column($this->roster, 'name')),
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?string $search, int $page, int $recordsPerPage) {
                $rows = $this->rows();

                if ($search) {
                    $rows = array_values(array_filter($rows, fn ($row) => str($row['name'])->contains($search, true)));
                }

                return new LengthAwarePaginator(array_slice($rows, ($page - 1) * $recordsPerPage, $recordsPerPage), count($rows), $recordsPerPage, $page);
            })
            ->paginated([25, 50, 100])
            // The filters are small buttons inside the table's card instead of tabs above it.
            // A custom header replaces Filament's, so it renders the header actions too.
            ->header(fn (Table $table) => view('overseer::partials.player-filters', [
                'tabs' => collect($this->getTabs())->map(fn (Tab $tab) => ['label' => $tab->getLabel(), 'badge' => $tab->getBadge()])->all(),
                'active' => array_key_exists((string) $this->activeTab, $this->getTabs()) ? $this->activeTab : array_key_first($this->getTabs()),
                'actions' => $table->getHeaderActions(),
            ]))
            ->columns([
                ImageColumn::make('head')
                    ->label('')
                    ->state(fn (array $record) => 'https://mc-heads.net/avatar/' . rawurlencode($record['name']) . '/64')
                    ->imageSize(32)
                    ->grow(false),
                TextColumn::make('name')
                    ->label(trans('overseer::overseer.players.columns.name'))
                    ->searchable()
                    ->weight(FontWeight::Medium),
                TextColumn::make('roles')
                    ->label(trans('overseer::overseer.players.columns.role'))
                    ->badge()
                    ->state(fn (array $record) => array_values(array_filter([
                        in_array($record['name'], $this->ops, true) ? trans('overseer::overseer.map.op') : null,
                        in_array($record['name'], $this->whitelist, true) ? trans('overseer::overseer.players.whitelisted') : null,
                        $this->activeTab !== 'banned' && $this->isBanned($record['name']) ? trans('overseer::overseer.players.banned') : null,
                    ])))
                    ->color(fn (string $state) => match ($state) {
                        trans('overseer::overseer.map.op') => 'warning',
                        trans('overseer::overseer.players.banned') => 'danger',
                        default => 'info',
                    }),
                TextColumn::make('gamemode')
                    ->label(trans('overseer::overseer.players.columns.game_mode'))
                    ->visible(fn () => $this->activeTab !== 'banned')
                    ->badge()
                    ->placeholder('')
                    ->state(fn (array $record) => isset($record['gamemode']) ? trans("overseer::overseer.players.game_modes.{$record['gamemode']}") : null)
                    ->color(fn (array $record) => match ($record['gamemode'] ?? null) {
                        'creative' => 'warning',
                        'spectator' => 'gray',
                        'adventure' => 'info',
                        default => 'success',
                    }),
                TextColumn::make('xp_level')
                    ->label(trans('overseer::overseer.players.columns.level'))
                    ->visible(fn () => $this->activeTab !== 'banned')
                    ->placeholder('')
                    ->state(fn (array $record) => $record['xp_level'] ?? null),
                TextColumn::make('world')
                    ->label(trans('overseer::overseer.players.columns.world'))
                    ->visible(fn () => $this->activeTab !== 'banned')
                    ->placeholder('')
                    ->state(fn (array $record) => isset($record['dimension'])
                        ? trans('overseer::overseer.map.worlds.' . match (preg_replace('/^minecraft:/', '', $record['dimension'])) {
                            'the_nether' => 'nether',
                            'the_end' => 'end',
                            default => 'overworld',
                        })
                        : null)
                    ->description(fn (array $record) => isset($record['x']) ? "{$record['x']}, {$record['y']}, {$record['z']}" : null),
                TextColumn::make('last_seen')
                    ->label(trans('overseer::overseer.players.columns.last_online'))
                    ->visible(fn () => $this->activeTab !== 'banned')
                    ->state(fn (array $record) => match (true) {
                        $record['is_online'] ?? false => trans('overseer::overseer.players.online_now'),
                        isset($record['last_seen']) => \Illuminate\Support\Carbon::createFromTimestamp($record['last_seen'])->diffForHumans(),
                        default => trans('overseer::overseer.players.never'),
                    })
                    ->tooltip(fn (array $record) => isset($record['last_seen']) && !($record['is_online'] ?? false)
                        ? \Illuminate\Support\Carbon::createFromTimestamp($record['last_seen'])->timezone(user()?->timezone ?? config('app.timezone'))->toDayDateTimeString()
                        : null)
                    ->color(fn (array $record) => ($record['is_online'] ?? false) ? 'success' : 'gray'),
                TextColumn::make('reason')
                    ->label(trans('overseer::overseer.players.columns.reason'))
                    ->visible(fn () => $this->activeTab === 'banned')
                    ->description(fn (array $record) => implode(' · ', array_filter([
                        $record['source'] ? trans('overseer::overseer.players.banned_by', ['name' => $record['source']]) : null,
                        $record['created'] ? substr($record['created'], 0, 10) : null,
                    ])))
                    ->wrap(),
                TextColumn::make('expires')
                    ->label(trans('overseer::overseer.players.columns.expires'))
                    ->visible(fn () => $this->activeTab === 'banned')
                    ->state(fn (array $record) => $this->banExpiry($record['name']) ?? trans('overseer::overseer.players.never')),
            ])
            ->recordActions([
                // grouped() keeps labels in the menu. Pelican's "icon buttons"
                // preference otherwise turns every action into a bare icon.
                ActionGroup::make(array_map(fn (Action $action) => $action->grouped(), [
                    $this->opAction(),
                    $this->whitelistAction(),
                    $this->gamemodeAction(),
                    $this->giveAction(),
                    $this->teleportAction(),
                    $this->kickAction(),
                    $this->banAction(),
                    $this->unbanAction(),
                ]))
                    ->label(trans('overseer::overseer.players.actions.menu'))
                    ->icon('tabler-dots-vertical')
                    ->button()
                    ->color('gray')
                    ->size('sm'),
            ])
            ->headerActions([
                Action::make('add_to_whitelist')
                    ->label(trans('overseer::overseer.players.add_to_whitelist'))
                    ->icon('tabler-user-plus')
                    ->visible(fn () => $this->can(Permission::PLAYERS_WHITELIST))
                    ->schema([
                        TextInput::make('name')
                            ->label(trans('overseer::overseer.players.columns.name'))
                            ->required()
                            ->regex('/^[.*]?[A-Za-z0-9_]{1,16}$/'),
                    ])
                    ->action(fn (array $data) => $this->runFor('whitelist', 'whitelist add', $data['name'], 'whitelist_added')),
            ])
            ->emptyStateHeading(fn () => $this->emptyHeading());
    }

    /** Called by the refresh control on the tab row. */
    public function refreshPlayers(bool $manual = false): void
    {
        $this->loadPlayers(fresh: $manual);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    private function kickAction(): Action
    {
        return Action::make('kick')
            ->label(trans('overseer::overseer.players.actions.kick'))
            ->icon('tabler-door-exit')
            ->color('warning')
            ->visible(fn (array $record) => ($record['is_online'] ?? false) && $this->can(Permission::PLAYERS_KICK))
            ->modalHeading(fn (array $record) => trans('overseer::overseer.players.kick_heading', ['name' => $record['name']]))
            ->schema([$this->reasonField()])
            ->action(function (array $record, array $data) {
                $reason = CommandInput::text($data['reason'] ?? '');
                $this->runFor('kick', 'kick', $record['name'], 'kicked', $reason);
            });
    }

    private function banAction(): Action
    {
        return Action::make('ban')
            ->label(trans('overseer::overseer.players.actions.ban'))
            ->icon('tabler-hammer')
            ->color('danger')
            ->visible(fn (array $record) => $this->activeTab !== 'banned' && !$this->isBanned($record['name']) && $this->can(Permission::PLAYERS_BAN))
            ->modalHeading(fn (array $record) => trans('overseer::overseer.players.ban_heading', ['name' => $record['name']]))
            ->modalSubmitActionLabel(trans('overseer::overseer.players.actions.ban'))
            ->schema([
                $this->reasonField(),
                Select::make('duration')
                    ->label(trans('overseer::overseer.players.duration'))
                    ->options([
                        '1' => trans('overseer::overseer.players.durations.hour'),
                        '24' => trans('overseer::overseer.players.durations.day'),
                        '168' => trans('overseer::overseer.players.durations.week'),
                        'forever' => trans('overseer::overseer.players.durations.forever'),
                    ])
                    ->default('forever')
                    ->selectablePlaceholder(false),
            ])
            ->action(function (array $record, array $data) {
                $reason = CommandInput::text($data['reason'] ?? '');
                $hours = $data['duration'] === 'forever' ? null : (int) $data['duration'];
                $shownReason = $hours ? trim($reason . ' (' . trans('overseer::overseer.players.ban_for', ['duration' => trans('overseer::overseer.players.durations.' . match ($hours) { 1 => 'hour', 24 => 'day', default => 'week' })]) . ')') : $reason;

                if (!$this->runFor('ban', 'ban', $record['name'], 'banned', $shownReason)) {
                    return;
                }

                // This ban replaces any earlier timed one, so the scheduler won't lift it.
                TimedBan::active()->where('server_id', $this->server()->id)->where('player', $record['name'])->update(['lifted_at' => now()]);

                if ($hours) {
                    TimedBan::create([
                        'server_id' => $this->server()->id,
                        'user_id' => user()?->id,
                        'player' => $record['name'],
                        'reason' => $reason ?: null,
                        'expires_at' => now()->addHours($hours),
                    ]);
                }
            });
    }

    private function gamemodeAction(): Action
    {
        return Action::make('gamemode')
            ->label(trans('overseer::overseer.players.actions.gamemode'))
            ->icon('tabler-device-gamepad-2')
            ->color('gray')
            ->visible(fn (array $record) => ($record['is_online'] ?? false) && $this->can(Permission::PLAYERS_CHEAT))
            ->modalHeading(fn (array $record) => trans('overseer::overseer.players.gamemode_heading', ['name' => $record['name']]))
            ->modalSubmitActionLabel(trans('overseer::overseer.players.actions.gamemode'))
            ->fillForm(fn (array $record) => ['mode' => $record['gamemode'] ?? 'survival'])
            ->schema([Select::make('mode')
                    ->label(trans('overseer::overseer.players.game_mode'))
                    ->options(collect(CommandInput::GAME_MODES)->mapWithKeys(fn ($mode) => [$mode => trans("overseer::overseer.players.game_modes.$mode")])->all())
                    ->default('survival')
                    ->selectablePlaceholder(false)
                    ->required()])
            ->action(fn (array $record, array $data) => $this->runCommand(
                'gamemode',
                $record['name'],
                fn (string $name) => sprintf('gamemode %s %s', CommandInput::gameMode($data['mode']), $name),
                'gamemode_changed',
            ));
    }

    private function giveAction(): Action
    {
        return Action::make('give')
            ->label(trans('overseer::overseer.players.actions.give'))
            ->icon('tabler-gift')
            ->color('gray')
            ->visible(fn (array $record) => ($record['is_online'] ?? false) && $this->can(Permission::PLAYERS_CHEAT))
            ->modalHeading(fn (array $record) => trans('overseer::overseer.players.give_heading', ['name' => $record['name']]))
            ->modalSubmitActionLabel(trans('overseer::overseer.players.actions.give'))
            ->schema([
                Grid::make(3)->schema([
                    TextInput::make('item')
                        ->label(trans('overseer::overseer.players.item'))
                        ->helperText(trans('overseer::overseer.players.item_help'))
                        ->placeholder('minecraft:diamond')
                        ->datalist(['minecraft:diamond', 'minecraft:iron_ingot', 'minecraft:golden_apple', 'minecraft:ender_pearl', 'minecraft:cooked_beef', 'minecraft:torch', 'minecraft:oak_log', 'minecraft:elytra', 'minecraft:totem_of_undying'])
                        ->regex('/^(?:[a-z0-9_.-]+:)?[a-z0-9_.\/-]{1,100}$/i')
                        ->required()
                        ->columnSpan(2),
                    TextInput::make('count')
                        ->label(trans('overseer::overseer.players.count'))
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(6400)
                        ->default(1)
                        ->required(),
                ]),
            ])
            ->action(fn (array $record, array $data) => $this->runCommand(
                'give',
                $record['name'],
                fn (string $name) => sprintf('give %s %s %d', $name, CommandInput::itemId($data['item']), max(1, min(6400, (int) $data['count']))),
                'given',
            ));
    }

    private function teleportAction(): Action
    {
        return Action::make('teleport')
            ->label(trans('overseer::overseer.players.actions.teleport'))
            ->icon('tabler-arrows-move')
            ->color('gray')
            ->visible(fn (array $record) => ($record['is_online'] ?? false) && $this->can(Permission::PLAYERS_CHEAT))
            ->modalHeading(fn (array $record) => trans('overseer::overseer.players.teleport_heading', ['name' => $record['name']]))
            ->modalSubmitActionLabel(trans('overseer::overseer.players.actions.teleport'))
            ->schema(fn (array $record) => [
                Radio::make('to')
                    ->label(trans('overseer::overseer.players.teleport_to'))
                    ->options([
                        'player' => trans('overseer::overseer.players.teleport_player'),
                        'coords' => trans('overseer::overseer.players.teleport_coords'),
                    ])
                    ->default(fn () => count(array_filter(array_column($this->online ?? [], 'name'), fn ($name) => $name !== $record['name'])) > 0 ? 'player' : 'coords')
                    ->inline()
                    ->live(),
                Select::make('target')
                    ->label(trans('overseer::overseer.players.columns.name'))
                    ->options(fn () => collect($this->online ?? [])->pluck('name')->reject(fn ($name) => $name === $record['name'])->mapWithKeys(fn ($name) => [$name => $name])->all())
                    ->visible(fn (Get $get) => $get('to') === 'player')
                    ->required(fn (Get $get) => $get('to') === 'player'),
                Grid::make(3)
                    ->visible(fn (Get $get) => $get('to') === 'coords')
                    ->schema(array_map(fn (string $axis) => TextInput::make($axis)
                        ->label(strtoupper($axis))
                        ->numeric()
                        ->integer()
                        ->minValue($axis === 'y' ? -2048 : -29999984)
                        ->maxValue($axis === 'y' ? 2048 : 29999984)
                        ->default($record[$axis] ?? 0)
                        ->required(fn (Get $get) => $get('to') === 'coords'), ['x', 'y', 'z'])),
                Select::make('dimension')
                    ->label(trans('overseer::overseer.players.dimension'))
                    ->options([
                        'minecraft:overworld' => trans('overseer::overseer.map.worlds.overworld'),
                        'minecraft:the_nether' => trans('overseer::overseer.map.worlds.nether'),
                        'minecraft:the_end' => trans('overseer::overseer.map.worlds.end'),
                    ])
                    ->default('minecraft:' . preg_replace('/^minecraft:/', '', (string) ($record['dimension'] ?? 'overworld')))
                    ->selectablePlaceholder(false)
                    ->visible(fn (Get $get) => $get('to') === 'coords'),
            ])
            ->action(fn (array $record, array $data) => $this->runCommand(
                'teleport',
                $record['name'],
                fn (string $name) => CommandInput::teleport($name, $data),
                'teleported',
            ));
    }

    /** Builds a command for one player after checking the name, runs it, and reports the result. */
    private function runCommand(string $action, string $player, callable $command, string $notification): bool
    {
        try {
            $name = CommandInput::playerName($player);
            $reply = app(ConsoleService::class)->run($this->server(), $action, $command($name), $name);
            if (self::refused($reply)) {
                throw new \RuntimeException($reply);
            }

            Notification::make()
                ->title(trans("overseer::overseer.players.notifications.$notification", ['name' => $name]))
                ->body($reply ?: null)
                ->success()
                ->send();

            $this->loadPlayers();

            return true;
        } catch (Exception $exception) {
            Notification::make()
                ->title(trans('overseer::overseer.players.notifications.failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return false;
        }
    }

    private function unbanAction(): Action
    {
        return Action::make('unban')
            ->label(trans('overseer::overseer.players.actions.unban'))
            ->icon('tabler-rotate')
            ->color('success')
            ->visible(fn () => $this->activeTab === 'banned' && $this->can(Permission::PLAYERS_BAN))
            ->requiresConfirmation()
            ->action(function (array $record) {
                if ($this->runFor('unban', 'pardon', $record['name'], 'unbanned')) {
                    TimedBan::active()->where('server_id', $this->server()->id)->where('player', $record['name'])->update(['lifted_at' => now()]);
                }
            });
    }

    private function opAction(): Action
    {
        $isOp = fn (array $record) => in_array($record['name'], $this->ops, true);

        return Action::make('op')
            ->label(fn (array $record) => $isOp($record) ? trans('overseer::overseer.players.actions.deop') : trans('overseer::overseer.players.actions.op'))
            ->icon(fn (array $record) => $isOp($record) ? 'tabler-crown-off' : 'tabler-crown')
            ->color('gray')
            ->visible(fn () => $this->activeTab !== 'banned' && $this->can(Permission::PLAYERS_OP))
            ->requiresConfirmation()
            ->modalHeading(fn (array $record) => trans($isOp($record) ? 'overseer::overseer.map.deop_heading' : 'overseer::overseer.map.op_heading', ['name' => $record['name']]))
            ->modalDescription(fn (array $record) => $isOp($record) ? null : trans('overseer::overseer.players.op_warning'))
            ->modalSubmitActionLabel(fn (array $record) => trans($isOp($record) ? 'overseer::overseer.players.actions.deop' : 'overseer::overseer.players.actions.op'))
            ->action(fn (array $record) => $isOp($record)
                ? $this->runFor('deop', 'deop', $record['name'], 'deopped')
                : $this->runFor('op', 'op', $record['name'], 'opped'));
    }

    private function whitelistAction(): Action
    {
        $listed = fn (array $record) => in_array($record['name'], $this->whitelist, true);

        return Action::make('whitelist')
            ->label(fn (array $record) => $listed($record) ? trans('overseer::overseer.players.actions.unwhitelist') : trans('overseer::overseer.players.actions.whitelist'))
            ->icon(fn (array $record) => $listed($record) ? 'tabler-playlist-x' : 'tabler-playlist-add')
            ->color('gray')
            ->visible(fn () => $this->activeTab !== 'banned' && $this->can(Permission::PLAYERS_WHITELIST))
            ->action(fn (array $record) => $listed($record)
                ? $this->runFor('whitelist', 'whitelist remove', $record['name'], 'whitelist_removed')
                : $this->runFor('whitelist', 'whitelist add', $record['name'], 'whitelist_added'));
    }

    private function reasonField(): TextInput
    {
        return TextInput::make('reason')
            ->label(trans('overseer::overseer.players.reason'))
            ->helperText(trans('overseer::overseer.players.reason_help'))
            ->datalist(config('overseer.reasons', []))
            ->maxLength(200);
    }

    /**
     * Runs "<verb> <player> [<suffix>]" after checking the name, and reports the result.
     * $suffix must already be cleaned with CommandInput::text().
     */
    private function runFor(string $action, string $verb, string $player, string $notification, string $suffix = ''): bool
    {
        try {
            $name = CommandInput::playerName($player);
            $reply = app(ConsoleService::class)->run($this->server(), $action, trim("$verb $name $suffix"), $name);
            if (self::refused($reply)) {
                throw new \RuntimeException($reply);
            }

            Notification::make()
                ->title(trans("overseer::overseer.players.notifications.$notification", ['name' => $name]))
                ->body($reply ?: null)
                ->success()
                ->send();

            $this->loadPlayers(fresh: in_array($action, ['whitelist', 'ban', 'unban'], true));

            return true;
        } catch (Exception $exception) {
            Notification::make()
                ->title(trans('overseer::overseer.players.notifications.failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return false;
        }
    }

    /** Replies where Minecraft didn't do what was asked, though the command ran. */
    private static function refused(?string $reply): bool
    {
        return $reply !== null && (bool) preg_match('/^(Nothing changed|That player does not exist|No player was found|Unknown or incomplete command|Incorrect argument)/i', $reply);
    }

    private function isBanned(string $player): bool
    {
        return in_array(strtolower($player), array_map('strtolower', array_column($this->banned, 'name')), true);
    }

    private function banExpiry(string $player): ?string
    {
        $at = $this->timedBans[$player] ?? null;
        if ($at === null) {
            return null;
        }

        // Expired but not lifted yet: the scheduler pardons within a minute while the server runs.
        return $at <= time()
            ? trans('overseer::overseer.players.lifting_soon')
            : \Illuminate\Support\Carbon::createFromTimestamp($at)->diffForHumans();
    }

    private function emptyHeading(): string
    {
        if (filled($this->getTableSearch())) {
            return trans('overseer::overseer.players.empty.no_match');
        }

        return in_array($this->activeTab, [null, 'all'], true)
            ? trans('overseer::overseer.players.empty.nobody')
            : trans('overseer::overseer.players.empty.none');
    }

    private function can(string $permission): bool
    {
        return Permission::allows($permission, $this->server());
    }

    private function server(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }
}
