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
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Headdetect\Overseer\Filament\Server\Clusters\Overseer;
use Headdetect\Overseer\Filament\Server\Concerns\RunsQuickCommands;
use Headdetect\Overseer\Models\TimedBan;
use Headdetect\Overseer\Services\ConsoleService;
use Headdetect\Overseer\Services\Map\MapService;
use Headdetect\Overseer\Services\Map\Surface;
use Headdetect\Overseer\Services\OverviewService;
use Headdetect\Overseer\Services\PlayerService;
use Headdetect\Overseer\Support\CommandInput;
use Headdetect\Overseer\Support\Permission;
use Headdetect\Overseer\Support\ServerStats;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;

class Overview extends Page
{
    use BlockAccessInConflict;
    use RunsQuickCommands;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-layout-dashboard';

    protected static ?string $slug = 'overview';

    protected static ?string $cluster = Overseer::class;

    protected static ?int $navigationSort = 1;

    protected string $view = 'overseer::overview';

    /** @var array{status: string, port: ?int, worlds: array<int, array<string, mixed>>} */
    #[Locked]
    public array $source = [];

    /** @var string[] */
    #[Locked]
    public array $ops = [];

    public static function canAccess(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return (Permission::allows(Permission::MAP_VIEW, $server)
            || Permission::allows(Permission::COMMANDS_WORLD, $server)
            || Permission::allows(Permission::COMMANDS_OPS, $server))
            && parent::canAccess();
    }

    /** The stats, map and players need overseer.map-view. The command panels have their own permissions. */
    public function canSeeMap(): bool
    {
        return Permission::allows(Permission::MAP_VIEW, $this->server());
    }

    public static function getNavigationLabel(): string
    {
        return trans('overseer::overseer.overview.title');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        if (!$this->canSeeMap()) {
            return;
        }

        $this->source = app(MapService::class)->source($this->server());
        $this->ops = app(PlayerService::class)->ops($this->server());
    }

    /** The quick stats above the map. The view polls this. */
    public function stats(): array
    {
        return app(OverviewService::class)->stats($this->server());
    }

    /** What the map script needs. Built here so the view stays simple. */
    public function mapConfig(): array
    {
        $squaremap = $this->source['status'] === MapService::READY;

        return [
            'mode' => $squaremap ? 'squaremap' : 'grid',
            'worlds' => $squaremap ? self::labelWorlds($this->source['worlds']) : self::gridWorlds(),
            'tileBase' => url("/overseer/servers/{$this->server()->uuid}/map") . '/',
            'headUrl' => 'https://mc-heads.net/avatar/{name}/64',
            'refresh' => (int) config('overseer.map.refresh', 5),
            'canTeleport' => $this->can(Permission::PLAYERS_CHEAT),
            'surfaceUrl' => route('overseer.map.surface', ['server' => $this->server()->uuid]),
            'feedUrl' => route('overseer.map.feed', ['server' => $this->server()->uuid]),
            'labels' => [
                'updatedSeconds' => trans('overseer::overseer.map.updated_seconds'),
                'updatedMinutes' => trans('overseer::overseer.map.updated_minutes'),
                'joined' => trans('overseer::overseer.chat.joined'),
                'left' => trans('overseer::overseer.chat.left'),
                'day' => trans('overseer::overseer.overview.day'),
                'phases' => trans('overseer::overseer.overview.phases'),
                'providers' => trans('overseer::overseer.overview.providers'),
            ],
        ];
    }

    /** The player's game mode over RCON, for the Game mode form's default. */
    private function currentGameMode(string $player): string
    {
        try {
            $name = CommandInput::playerName($player);
            $mode = PlayerService::parseNumber((string) app(ConsoleService::class)->query($this->server(), "data get entity $name playerGameType"));

            return CommandInput::GAME_MODES[$mode] ?? 'survival';
        } catch (Exception) {
            return 'survival';
        }
    }

    /** Teleport any online player to coordinates, from the Quick actions card. */
    public function teleportCoordsAction(): Action
    {
        $axis = fn (string $name) => TextInput::make($name)
            ->label(strtoupper($name))
            ->numeric()
            ->integer()
            ->minValue($name === 'y' ? -2048 : -29999984)
            ->maxValue($name === 'y' ? 2048 : 29999984);

        return Action::make('teleportCoords')
            ->visible(fn () => $this->can(Permission::PLAYERS_CHEAT))
            ->modalHeading(trans('overseer::overseer.map.teleport.heading'))
            ->modalSubmitActionLabel(trans('overseer::overseer.map.point.teleport'))
            ->schema([
                Select::make('player')
                    ->label(trans('overseer::overseer.map.point.player'))
                    ->options(function () {
                        $reply = app(ConsoleService::class)->query($this->server(), 'list');
                        $names = $reply !== null ? PlayerService::parseList($reply) : [];

                        return array_combine($names, $names) ?: [];
                    })
                    ->required(),
                Select::make('dimension')
                    ->label(trans('overseer::overseer.players.dimension'))
                    ->options([
                        'minecraft:overworld' => trans('overseer::overseer.map.worlds.overworld'),
                        'minecraft:the_nether' => trans('overseer::overseer.map.worlds.nether'),
                        'minecraft:the_end' => trans('overseer::overseer.map.worlds.end'),
                    ])
                    ->default('minecraft:overworld')
                    ->selectablePlaceholder(false)
                    ->required(),
                Grid::make(3)->schema([
                    $axis('x')->required(),
                    $axis('y')->required(fn (Get $get) => !$get('ground'))->disabled(fn (Get $get) => (bool) $get('ground'))->placeholder(fn (Get $get) => $get('ground') ? trans('overseer::overseer.map.teleport.auto') : null),
                    $axis('z')->required(),
                ]),
                Toggle::make('ground')
                    ->label(trans('overseer::overseer.map.teleport.ground'))
                    ->helperText(trans('overseer::overseer.map.teleport.ground_help'))
                    ->default(true)
                    ->live(),
            ])
            ->action(function (array $data) {
                $x = (int) $data['x'];
                $z = (int) $data['z'];
                $y = ($data['ground'] ?? false)
                    ? app(Surface::class)->y($this->server(), $data['dimension'], $x, $z)
                    : (int) $data['y'];

                if ($y === null) {
                    Notification::make()->title(trans('overseer::overseer.players.notifications.failed'))->body(trans('overseer::overseer.map.point.no_ground'))->danger()->send();

                    return;
                }

                $this->teleportTo((string) $data['player'], $data['dimension'], $x, $y, $z);
            });
    }

    /** Teleports a player to a point clicked on the map. */
    public function teleportTo(string $player, string $world, int $x, int $y, int $z): bool
    {
        abort_unless($this->can(Permission::PLAYERS_CHEAT), 403);

        try {
            $name = CommandInput::playerName($player);
            $command = CommandInput::teleport($name, ['to' => 'coords', 'x' => $x, 'y' => $y, 'z' => $z, 'dimension' => Surface::dimension($world)]);
            $reply = app(ConsoleService::class)->run($this->server(), 'teleport', $command, $name);

            Notification::make()
                ->title(trans('overseer::overseer.players.notifications.teleported', ['name' => $name]))
                ->body($reply ?: null)
                ->success()
                ->send();

            return true;
        } catch (Exception $exception) {
            Notification::make()->title(trans('overseer::overseer.players.notifications.failed'))->body($exception->getMessage())->danger()->send();

            return false;
        }
    }

    /** Sends a chat message as "[Rcon] <panel user>: message", with say. */
    public function sendChat(string $message): bool
    {
        $server = $this->server();
        abort_unless($this->canRunOpsCommands(), 403);

        $text = CommandInput::text($message);
        if ($text === '') {
            return false;
        }

        try {
            $name = CommandInput::text(user()?->username ?? 'admin', 32);
            app(ConsoleService::class)->run($server, 'chat', "say $name: $text");
            cache()->forget("overseer:chat:$server->uuid");

            return true;
        } catch (Exception $exception) {
            Notification::make()->title(trans('overseer::overseer.chat.failed'))->body($exception->getMessage())->danger()->send();

            return false;
        }
    }

    /** Looks for squaremap again, for after an admin installs or sets it up. */
    public function checkAgain(): void
    {
        app(MapService::class)->source($this->server(), fresh: true);

        $this->redirect(static::getUrl(), navigate: true);
    }

    public function statusMessage(): ?array
    {
        $status = $this->source['status'];
        if ($status === MapService::READY) {
            return null;
        }

        return [
            'title' => trans("overseer::overseer.map.setup.$status.title"),
            'body' => trans("overseer::overseer.map.setup.$status.body", ['port' => $this->source['port'] ?? '']),
        ];
    }

    public function hasRcon(): bool
    {
        return app(ConsoleService::class)->hasRcon($this->server());
    }

    /** @return array{kick: bool, ban: bool, op: bool} */
    public function abilities(): array
    {
        return [
            'kick' => $this->can(Permission::PLAYERS_KICK),
            'ban' => $this->can(Permission::PLAYERS_BAN),
            'op' => $this->can(Permission::PLAYERS_OP),
            'gamemode' => $this->can(Permission::PLAYERS_CHEAT),
            'teleport' => $this->can(Permission::PLAYERS_CHEAT),
        ];
    }

    public function kickAction(): Action
    {
        return Action::make('kick')
            ->visible(fn () => $this->can(Permission::PLAYERS_KICK))
            ->color('warning')
            ->modalHeading(fn (array $arguments) => trans('overseer::overseer.players.kick_heading', ['name' => $arguments['name'] ?? '']))
            ->modalSubmitActionLabel(trans('overseer::overseer.players.actions.kick'))
            ->schema([$this->reasonField()])
            ->action(fn (array $arguments, array $data) => $this->runFor('kick', 'kick', $arguments['name'] ?? '', 'kicked', CommandInput::text($data['reason'] ?? '')));
    }

    public function banAction(): Action
    {
        return Action::make('ban')
            ->visible(fn () => $this->can(Permission::PLAYERS_BAN))
            ->color('danger')
            ->modalHeading(fn (array $arguments) => trans('overseer::overseer.players.ban_heading', ['name' => $arguments['name'] ?? '']))
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
            ->action(function (array $arguments, array $data) {
                $reason = CommandInput::text($data['reason'] ?? '');
                $hours = $data['duration'] === 'forever' ? null : (int) $data['duration'];
                $shownReason = $hours ? trim($reason . ' (' . trans('overseer::overseer.players.ban_for', ['duration' => trans('overseer::overseer.players.durations.' . match ($hours) { 1 => 'hour', 24 => 'day', default => 'week' })]) . ')') : $reason;

                if (!$this->runFor('ban', 'ban', $arguments['name'] ?? '', 'banned', $shownReason)) {
                    return;
                }

                TimedBan::active()->where('server_id', $this->server()->id)->where('player', CommandInput::playerName($arguments['name']))->update(['lifted_at' => now()]);

                if ($hours) {
                    TimedBan::create([
                        'server_id' => $this->server()->id,
                        'user_id' => user()?->id,
                        'player' => CommandInput::playerName($arguments['name']),
                        'reason' => $reason ?: null,
                        'expires_at' => now()->addHours($hours),
                    ]);
                }
            });
    }

    public function opAction(): Action
    {
        return Action::make('op')
            ->visible(fn () => $this->can(Permission::PLAYERS_OP))
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments) => trans('overseer::overseer.map.op_heading', ['name' => $arguments['name'] ?? '']))
            ->modalDescription(trans('overseer::overseer.players.op_warning'))
            ->modalSubmitActionLabel(trans('overseer::overseer.players.actions.op'))
            ->action(fn (array $arguments) => $this->runFor('op', 'op', $arguments['name'] ?? '', 'opped'));
    }

    public function gamemodeAction(): Action
    {
        return Action::make('gamemode')
            ->visible(fn () => $this->can(Permission::PLAYERS_CHEAT))
            ->modalHeading(fn (array $arguments) => trans('overseer::overseer.players.gamemode_heading', ['name' => $arguments['name'] ?? '']))
            ->modalSubmitActionLabel(trans('overseer::overseer.players.actions.gamemode'))
            ->fillForm(fn (array $arguments) => ['mode' => $this->currentGameMode($arguments['name'] ?? '')])
            ->schema([Select::make('mode')
                    ->label(trans('overseer::overseer.players.game_mode'))
                    ->options(collect(CommandInput::GAME_MODES)->mapWithKeys(fn ($mode) => [$mode => trans("overseer::overseer.players.game_modes.$mode")])->all())
                    ->default('survival')
                    ->selectablePlaceholder(false)
                    ->required()])
            ->action(fn (array $arguments, array $data) => $this->runFor('gamemode', 'gamemode ' . CommandInput::gameMode($data['mode']), $arguments['name'] ?? '', 'gamemode_changed'));
    }

    public function deopAction(): Action
    {
        return Action::make('deop')
            ->visible(fn () => $this->can(Permission::PLAYERS_OP))
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments) => trans('overseer::overseer.map.deop_heading', ['name' => $arguments['name'] ?? '']))
            ->modalSubmitActionLabel(trans('overseer::overseer.players.actions.deop'))
            ->action(fn (array $arguments) => $this->runFor('deop', 'deop', $arguments['name'] ?? '', 'deopped'));
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

            if (in_array($action, ['op', 'deop'], true)) {
                $this->ops = app(PlayerService::class)->ops($this->server());
            }

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

    /**
     * squaremap labels worlds with their ids ("minecraft:the_nether") unless
     * its config names them, and lists them in no useful order. Use the
     * translated names for the vanilla dimensions, and put them first.
     *
     * @param  array<int, array<string, mixed>>  $worlds
     * @return array<int, array<string, mixed>>
     */
    private static function labelWorlds(array $worlds): array
    {
        $rank = ['overworld' => 0, 'nether' => 1, 'end' => 2];

        $worlds = array_map(fn (array $world) => [
            ...$world,
            'label' => isset($rank[$world['type']]) && str_contains($world['label'], ':')
                ? trans("overseer::overseer.map.worlds.{$world['type']}")
                : $world['label'],
        ], $worlds);
        usort($worlds, fn ($a, $b) => ($rank[$a['type']] ?? 3) <=> ($rank[$b['type']] ?? 3));

        return $worlds;
    }

    /**
     * The three vanilla dimensions, for the plain grid shown without squaremap.
     * Zoom 4 is one pixel per block; each step down halves it.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function gridWorlds(): array
    {
        $world = fn (string $name, string $type) => [
            'name' => $name,
            'label' => trans("overseer::overseer.map.worlds.$type"),
            'type' => $type,
            'max' => 4,
            'def' => 2,
            'extra' => 2,
            'spawn' => ['x' => 0, 'z' => 0],
        ];

        return [$world('overworld', 'overworld'), $world('the_nether', 'nether'), $world('the_end', 'end')];
    }

    private function can(string $permission): bool
    {
        return Permission::allows($permission, $this->server());
    }

    protected function server(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }
}
