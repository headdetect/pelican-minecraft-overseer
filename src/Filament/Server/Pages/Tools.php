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
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Headdetect\Overseer\Filament\Server\Clusters\Overseer;
use Headdetect\Overseer\Services\ConsoleService;
use Headdetect\Overseer\Services\Map\MapService;
use Headdetect\Overseer\Services\Tools\Chunky;
use Headdetect\Overseer\Support\Permission;

class Tools extends Page
{
    use BlockAccessInConflict;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-tool';

    protected static ?string $slug = 'tools';

    protected static ?string $cluster = Overseer::class;

    protected static ?int $navigationSort = 5;

    protected string $view = 'overseer::tools';

    /** Vanilla dimensions, for servers without squaremap to list the worlds. */
    private const DEFAULT_WORLDS = ['minecraft:overworld', 'minecraft:the_nether', 'minecraft:the_end'];

    public static function canAccess(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return Permission::allows(Permission::TOOLS, $server) && parent::canAccess();
    }

    public static function getNavigationLabel(): string
    {
        return trans('overseer::overseer.tools.title');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    /** @return array{running: bool, starting: bool, installed: ?bool, tasks: array<int, array<string, mixed>>, saved: array<int, array<string, mixed>>} */
    public function chunky(): array
    {
        $status = $this->server()->retrieveStatus();
        $running = $status === ContainerStatus::Running;

        return [
            'running' => $running,
            'starting' => $status === ContainerStatus::Starting,
            ...($running ? app(Chunky::class)->status($this->server()) : ['installed' => null, 'tasks' => [], 'saved' => []]),
        ];
    }

    public function squaremapReady(): bool
    {
        return app(MapService::class)->source($this->server())['status'] === MapService::READY;
    }

    public function startAction(): Action
    {
        return Action::make('start')
            ->button()
            ->label(trans('overseer::overseer.tools.pregen.start'))
            ->icon('tabler-player-play')
            ->modalHeading(trans('overseer::overseer.tools.pregen.start_heading'))
            ->modalDescription(fn () => trans('overseer::overseer.tools.pregen.start_help') . ($this->chunky()['saved'] ? ' ' . trans('overseer::overseer.tools.pregen.replaces_saved') : ''))
            ->modalSubmitActionLabel(trans('overseer::overseer.tools.pregen.start'))
            ->schema([
                Select::make('world')
                    ->label(trans('overseer::overseer.tools.world'))
                    ->options($this->worlds())
                    ->default(self::DEFAULT_WORLDS[0])
                    ->selectablePlaceholder(false)
                    ->required(),
                Grid::make(2)->schema([
                    TextInput::make('radius')
                        ->label(trans('overseer::overseer.tools.pregen.radius'))
                        ->numeric()
                        ->integer()
                        ->minValue(16)
                        ->maxValue(Chunky::MAX_RADIUS)
                        ->default(2000)
                        ->suffix(trans('overseer::overseer.tools.pregen.blocks'))
                        ->live(debounce: 400)
                        ->required(),
                    Select::make('shape')
                        ->label(trans('overseer::overseer.tools.pregen.shape'))
                        ->options(['square' => trans('overseer::overseer.tools.pregen.shapes.square'), 'circle' => trans('overseer::overseer.tools.pregen.shapes.circle')])
                        ->default('square')
                        ->selectablePlaceholder(false)
                        ->live()
                        ->required(),
                ]),
                Toggle::make('spawn')
                    ->label(trans('overseer::overseer.tools.pregen.around_spawn'))
                    ->default(true)
                    ->live(),
                Grid::make(2)->visible(fn (Get $get) => !$get('spawn'))->schema([
                    TextInput::make('x')->label('X')->validationAttribute('X')->numeric()->integer()->minValue(-29999984)->maxValue(29999984)->default(0)->required(),
                    TextInput::make('z')->label('Z')->validationAttribute('Z')->numeric()->integer()->minValue(-29999984)->maxValue(29999984)->default(0)->required(),
                ]),
                Text::make(fn (Get $get) => $this->estimate($get('radius'), $get('shape'))),
            ])
            ->action(fn (array $data) => $this->notify(fn () => app(Chunky::class)->start(
                $this->server(),
                $data['world'],
                $data['spawn'] ? null : (int) $data['x'],
                $data['spawn'] ? null : (int) $data['z'],
                (int) $data['radius'],
                $data['shape'],
            )));
    }

    public function pauseAction(): Action
    {
        return Action::make('pause')
            ->button()
            ->label(trans('overseer::overseer.tools.pregen.pause'))
            ->icon('tabler-player-pause')
            ->color('gray')
            ->action(fn () => $this->notify(fn () => app(Chunky::class)->pause($this->server())));
    }

    public function continueAction(): Action
    {
        return Action::make('continue')
            ->button()
            ->label(trans('overseer::overseer.tools.pregen.continue'))
            ->icon('tabler-player-track-next')
            ->color('gray')
            ->tooltip(trans('overseer::overseer.tools.pregen.continue_help'))
            ->action(fn () => $this->notify(fn () => app(Chunky::class)->continue($this->server())));
    }

    public function cancelAction(): Action
    {
        return Action::make('cancel')
            ->button()
            ->label(trans('overseer::overseer.tools.pregen.cancel'))
            ->icon('tabler-player-stop')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(trans('overseer::overseer.tools.pregen.cancel_help'))
            ->action(fn () => $this->notify(fn () => app(Chunky::class)->cancel($this->server())));
    }

    public function renderAction(): Action
    {
        return Action::make('render')
            ->button()
            ->label(trans('overseer::overseer.tools.render.start'))
            ->icon('tabler-map')
            ->modalHeading(trans('overseer::overseer.tools.render.title'))
            ->modalDescription(trans('overseer::overseer.tools.render.start_help'))
            ->modalSubmitActionLabel(trans('overseer::overseer.tools.render.start'))
            ->schema([
                Select::make('world')
                    ->label(trans('overseer::overseer.tools.world'))
                    ->options($this->worlds())
                    ->default(self::DEFAULT_WORLDS[0])
                    ->selectablePlaceholder(false)
                    ->required(),
            ])
            ->action(function (array $data) {
                if (!Chunky::isWorld($data['world'])) {
                    return;
                }
                $this->notify(fn () => app(ConsoleService::class)->run($this->server(), 'render', "squaremap fullrender {$data['world']}"));
            });
    }

    /** @return array<string, string> world id => label */
    private function worlds(): array
    {
        $source = app(MapService::class)->source($this->server());
        $ids = $source['status'] === MapService::READY
            // squaremap names worlds like minecraft_the_nether.
            ? array_map(fn (array $world) => preg_replace('/_/', ':', $world['name'], 1), $source['worlds'])
            : self::DEFAULT_WORLDS;

        return collect($ids)->filter(fn ($id) => Chunky::isWorld($id))->mapWithKeys(fn ($id) => [$id => $id])->all();
    }

    private function estimate(mixed $radius, ?string $shape): string
    {
        if (!is_numeric($radius) || (int) $radius < 16 || (int) $radius > Chunky::MAX_RADIUS) {
            return '';
        }

        $chunks = Chunky::chunkCount((int) $radius, $shape ?? 'square');

        return trans('overseer::overseer.tools.pregen.estimate', ['chunks' => number_format($chunks)]);
    }

    private function notify(callable $command): void
    {
        try {
            $reply = $command();
            Notification::make()->title(trans('overseer::overseer.tools.sent'))->body($reply ? preg_replace('/^\[Chunky\]\s*/', '', $reply) : null)->success()->send();
        } catch (Exception $exception) {
            Notification::make()->title(trans('overseer::overseer.tools.failed'))->body($exception->getMessage())->danger()->send();
        }
    }

    private function server(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }
}
