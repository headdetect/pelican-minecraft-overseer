<?php

namespace Headdetect\Overseer\Filament\Server\Pages;

use App\Enums\ContainerStatus;
use App\Models\Server;
use App\Traits\Filament\BlockAccessInConflict;
use Exception;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Resources\Concerns\HasTabs;
use Filament\Schemas\Components\EmbeddedTable;
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

    /** @var string[] */
    public array $known = [];

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
        $this->loadPlayers();
        $this->loadDefaultActiveTab();
    }

    protected function loadPlayers(): void
    {
        $server = $this->server();
        $players = app(PlayerService::class);

        $this->online = $server->retrieveStatus() === ContainerStatus::Running ? $players->online($server) : [];
        $this->ops = $players->ops($server);
        $this->whitelist = $players->whitelist($server);
        $this->banned = $players->banned($server);
        $this->known = $players->known($server);
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return [
            'online' => Tab::make('online')->label(trans('overseer::overseer.players.tabs.online'))->badge(fn () => $this->online === null ? null : count($this->online)),
            'known' => Tab::make('known')->label(trans('overseer::overseer.players.tabs.known'))->badge(fn () => count($this->known)),
            'ops' => Tab::make('ops')->label(trans('overseer::overseer.players.tabs.ops'))->badge(fn () => count($this->ops)),
            'whitelist' => Tab::make('whitelist')->label(trans('overseer::overseer.players.tabs.whitelist'))->badge(fn () => count($this->whitelist)),
            'banned' => Tab::make('banned')->label(trans('overseer::overseer.players.tabs.banned'))->badge(fn () => count($this->banned) ?: null),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    protected function rows(): array
    {
        $byName = fn (array $names) => array_map(fn ($name) => ['name' => $name], $names);
        $onlineNames = array_column($this->online ?? [], 'name');

        return match ($this->activeTab) {
            'known' => array_map(fn ($name) => ['name' => $name, 'is_online' => in_array($name, $onlineNames, true)], $this->known),
            'ops' => $byName($this->ops),
            'whitelist' => $byName($this->whitelist),
            'banned' => $this->banned,
            default => array_map(fn ($player) => [...$player, 'is_online' => true], $this->online ?? []),
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
                        in_array($record['name'], $this->ops, true) ? 'OP' : null,
                        in_array($record['name'], $this->whitelist, true) ? trans('overseer::overseer.players.whitelisted') : null,
                    ])))
                    ->color(fn (string $state) => $state === 'OP' ? 'warning' : 'info'),
                TextColumn::make('location')
                    ->label(trans('overseer::overseer.players.columns.location'))
                    ->fontFamily(FontFamily::Mono)
                    ->visible(fn () => !$this->activeTab || $this->activeTab === 'online')
                    ->state(fn (array $record) => isset($record['x'])
                        ? str($record['dimension'] ?? 'overworld')->headline() . " · {$record['x']}, {$record['y']}, {$record['z']}"
                        : null),
                TextColumn::make('status')
                    ->label(trans('overseer::overseer.players.columns.status'))
                    ->visible(fn () => $this->activeTab === 'known')
                    ->state(fn (array $record) => ($record['is_online'] ?? false) ? trans('overseer::overseer.players.online') : trans('overseer::overseer.players.offline'))
                    ->color(fn (array $record) => ($record['is_online'] ?? false) ? 'success' : 'gray'),
                TextColumn::make('reason')
                    ->label(trans('overseer::overseer.players.columns.reason'))
                    ->visible(fn () => $this->activeTab === 'banned')
                    ->description(fn (array $record) => trim(($record['source'] ? 'by ' . $record['source'] : '') . ($record['created'] ? ' · ' . substr($record['created'], 0, 10) : ''), ' ·'))
                    ->wrap(),
                TextColumn::make('expires')
                    ->label(trans('overseer::overseer.players.columns.expires'))
                    ->visible(fn () => $this->activeTab === 'banned')
                    ->state(fn (array $record) => $this->banExpiry($record['name']) ?? trans('overseer::overseer.players.never')),
            ])
            ->recordActions([
                $this->opAction(),
                $this->whitelistAction(),
                $this->kickAction(),
                $this->banAction(),
                $this->unbanAction(),
            ])
            ->headerActions([
                Action::make('refresh')
                    ->label(trans('overseer::overseer.players.refresh'))
                    ->icon('tabler-refresh')
                    ->color('gray')
                    ->action(fn () => $this->loadPlayers()),
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
            ->emptyStateHeading(fn () => $this->emptyHeading())
            ->emptyStateDescription(fn () => $this->emptyDescription());
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getTabsContentComponent(),
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
            ->visible(fn () => $this->activeTab !== 'banned' && $this->can(Permission::PLAYERS_BAN))
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
                $shownReason = $hours ? trim($reason . ' (' . trans('overseer::overseer.players.ban_for', ['hours' => $hours]) . ')') : $reason;

                if (!$this->runFor('ban', 'ban', $record['name'], 'banned', $shownReason)) {
                    return;
                }

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
            ->requiresConfirmation(fn (array $record) => !$isOp($record))
            ->modalDescription(trans('overseer::overseer.players.op_warning'))
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

    private function banExpiry(string $player): ?string
    {
        $ban = TimedBan::active()->where('server_id', $this->server()->id)->where('player', $player)->latest('expires_at')->first();

        return $ban?->expires_at->diffForHumans();
    }

    private function emptyHeading(): string
    {
        if (($this->activeTab ?? 'online') !== 'online') {
            return trans('overseer::overseer.players.empty.none');
        }

        if ($this->server()->retrieveStatus() !== ContainerStatus::Running) {
            return trans('overseer::overseer.players.empty.offline');
        }

        return $this->online === null
            ? trans('overseer::overseer.players.empty.no_rcon')
            : trans('overseer::overseer.players.empty.nobody');
    }

    private function emptyDescription(): ?string
    {
        if (($this->activeTab ?? 'online') === 'online' && $this->online === null && $this->server()->retrieveStatus() === ContainerStatus::Running) {
            return trans('overseer::overseer.players.empty.no_rcon_help');
        }

        return null;
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
