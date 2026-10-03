<?php

namespace Headdetect\Overseer\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Facades\Activity;
use App\Filament\Server\Resources\Files\Pages\EditFiles;
use App\Filament\Server\Pages\ServerFormPage;
use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use Exception;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Slider;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Headdetect\Overseer\Filament\Server\Clusters\Overseer;
use Headdetect\Overseer\Services\ConfigFiles;
use Headdetect\Overseer\Support\ConfigSchema;
use Headdetect\Overseer\Support\Permission;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;

class Config extends ServerFormPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'tabler-settings-2';

    protected static ?string $slug = 'config';

    protected static ?string $cluster = Overseer::class;

    protected static ?int $navigationSort = 4;

    /** @var array<string, array<string, bool|int|string>> values as loaded, per source and key */
    public array $original = [];

    /** @var array<string, string[]> keys in the file we have no description for */
    public array $advancedKeys = [];

    /** @var array<string, string> why a source can't be shown */
    public array $unavailable = [];

    /** Set after saving settings that only apply on restart. */
    public bool $restartNeeded = false;

    public static function canAccess(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return (Permission::allows(Permission::CONFIG_VIEW, $server) || Permission::allows(Permission::CONFIG_EDIT, $server))
            && parent::canAccess();
    }

    public static function getNavigationLabel(): string
    {
        return trans('overseer::overseer.config.title');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    protected function fillForm(): void
    {
        $data = ['_search' => '', '_advanced' => false, '_file' => null];
        $this->restartNeeded = (bool) cache()->get("overseer:restart-needed:{$this->getRecord()->uuid}", false);

        foreach (array_keys(ConfigSchema::SOURCES) as $source) {
            try {
                $loaded = app(ConfigFiles::class)->load($this->getRecord(), $source);
            } catch (Exception $exception) {
                report($exception);
                $loaded = ['values' => [], 'advanced' => [], 'unavailable' => 'error'];
            }

            $this->original[$source] = $loaded['values'];
            $this->advancedKeys[$source] = $loaded['advanced'];
            if ($loaded['unavailable']) {
                $this->unavailable[$source] = $loaded['unavailable'];
            }

            foreach ($loaded['values'] as $key => $value) {
                $data[$source][ConfigSchema::fieldName($key)] = $value;
            }
        }

        $this->form->fill($data);
    }

    protected function getDefaultHeaderActions(): array
    {
        $changed = fn () => $this->canEdit() && $this->changeCount() > 0;
        $canRestart = fn () => user()?->can(SubuserPermission::ControlRestart, $this->getRecord()) ?? false;

        return [
            // Not a button: a note beside the save buttons when a change only applies on restart.
            Action::make('restartNote')
                ->label(fn () => trans($this->changesRcon() ? 'overseer::overseer.config.rcon_note' : 'overseer::overseer.config.restart_note'))
                ->icon('tabler-info-circle')
                ->color('warning')
                ->link()
                ->disabled()
                ->extraAttributes(['class' => 'us-config-note'])
                ->visible(fn () => $changed() && $this->restartChangeCount() > 0),
            Action::make('invalidNote')
                ->label(trans('overseer::overseer.config.invalid_note'))
                ->icon('tabler-alert-circle')
                ->color('danger')
                ->link()
                ->disabled()
                ->extraAttributes(['class' => 'us-config-note is-danger'])
                ->visible(fn () => $changed() && $this->hasInvalidChanges()),
            Action::make('discard')
                ->button()
                ->label(trans('overseer::overseer.config.discard'))
                ->color('gray')
                ->visible($changed)
                ->action(fn () => $this->discard()),
            Action::make('save')
                ->button()
                ->label(fn () => trans_choice('overseer::overseer.config.save', $this->changeCount(), ['count' => $this->changeCount()]))
                ->icon('tabler-device-floppy')
                ->color(fn () => $this->restartChangeCount() > 0 && $canRestart() ? 'gray' : 'primary')
                ->disabled(fn () => $this->hasInvalidChanges())
                // Overseer reads RCON details from the file, so an RCON change would cut it off until a restart.
                ->visible(fn () => $changed() && !($this->changesRcon() && $canRestart()))
                ->modalHeading(trans('overseer::overseer.config.review_heading'))
                ->modalDescription(trans('overseer::overseer.config.review_help'))
                ->modalContent(fn () => $this->diff())
                ->modalSubmitActionLabel(trans('overseer::overseer.config.save_changes'))
                ->action(fn () => $this->saveSettings()),
            Action::make('saveRestart')
                ->button()
                ->label(trans('overseer::overseer.config.save_restart'))
                ->icon('tabler-reload')
                ->disabled(fn () => $this->hasInvalidChanges())
                ->visible(fn () => $changed() && $this->restartChangeCount() > 0 && $canRestart())
                ->modalHeading(trans('overseer::overseer.config.review_heading'))
                ->modalDescription(trans('overseer::overseer.config.save_restart_help'))
                ->modalContent(fn () => $this->diff())
                ->modalSubmitActionLabel(trans('overseer::overseer.config.save_restart'))
                ->action(function () {
                    if ($this->saveSettings()) {
                        $this->restart();
                    }
                }),
            Action::make('restart')
                ->button()
                ->label(trans('overseer::overseer.config.restart'))
                ->icon('tabler-reload')
                ->color('warning')
                ->visible(fn () => $this->restartNeeded && !$changed())
                ->authorize($canRestart)
                ->requiresConfirmation()
                ->modalHeading(trans('overseer::overseer.config.restart_heading'))
                ->modalDescription(trans('overseer::overseer.config.restart_help'))
                ->action(fn () => $this->restart()),
        ];
    }

    public function form(Schema $schema): Schema
    {
        $tabs = [];
        foreach (array_keys(ConfigSchema::SOURCES) as $source) {
            // Not a Paper server: no Paper tab.
            if ($source === 'paper' && ($this->unavailable[$source] ?? null) === 'missing') {
                continue;
            }
            $tabs[] = $this->sourceTab($source);
        }
        $tabs[] = $this->filesTab();

        return parent::form($schema)->components([
            Grid::make(['default' => 1, 'sm' => 3])->columnSpanFull()->schema([
                TextInput::make('_search')
                    ->hiddenLabel()
                    ->placeholder(trans('overseer::overseer.config.search'))
                    ->prefixIcon('tabler-search')
                    ->live(debounce: 300)
                    ->columnSpan(['default' => 1, 'sm' => 2]),
                Toggle::make('_advanced')
                    ->label(trans('overseer::overseer.config.advanced'))
                    ->helperText(trans('overseer::overseer.config.advanced_help'))
                    ->live(),
            ]),
            Tabs::make('sources')
                ->vertical()
                ->persistTabInQueryString('source')
                ->columnSpanFull()
                ->tabs($tabs),
        ]);
    }

    /**
     * A list of config files for mods whose settings the form doesn't know.
     * Picking one opens it in Pelican's own file editor, which checks the
     * user's file permissions itself.
     */
    private function filesTab(): Tab
    {
        return Tab::make('files')
            ->label(trans('overseer::overseer.config.files.title'))
            ->icon('tabler-file-code')
            ->schema([
                Callout::make(trans('overseer::overseer.config.files.help'))->info(),
                Select::make('_file')
                    ->label(trans('overseer::overseer.config.files.file'))
                    ->placeholder(trans('overseer::overseer.config.files.pick'))
                    ->options(fn () => collect(app(ConfigFiles::class)->editableFiles($this->getRecord()))->mapWithKeys(fn ($path) => [$path => $path])->all())
                    ->searchable()
                    ->live()
                    ->disabled(!$this->canEdit())
                    ->dehydrated(false)
                    ->afterStateUpdated(function (?string $state) {
                        if ($this->canEdit() && $state && in_array($state, app(ConfigFiles::class)->editableFiles($this->getRecord()), true)) {
                            $this->redirect(EditFiles::getUrl(['path' => encode_path($state)]));
                        }
                    }),
            ]);
    }

    private function sourceTab(string $source): Tab
    {
        $schema = ConfigSchema::source($source);
        // The name is the key Filament writes to ?source=, so it has to stay stable.
        $tab = Tab::make($source)->label($schema['title'])->icon($schema['icon']);

        if ($reason = $this->unavailable[$source] ?? null) {
            return $tab->schema([
                Callout::make(trans("overseer::overseer.config.unavailable.$reason"))
                    ->description(trans("overseer::overseer.config.unavailable.{$reason}_help"))
                    ->warning(),
            ]);
        }

        $entries = $this->entriesFor($source);
        $groups = [];
        foreach ($entries as $key => $entry) {
            $groups[$entry['group']][$key] = $entry;
        }

        $sections = [];
        foreach ($groups as $group => $settings) {
            // Plain headings with space between groups, so there are no cards inside the tab's card.
            $sections[] = Section::make($group)
                ->contained(false)
                ->extraAttributes(['class' => 'us-config-group'])
                ->hidden(fn () => !$this->anyVisible($settings))
                ->schema(array_map(fn (string $key) => $this->field($source, $key, $settings[$key]), array_keys($settings)));
        }

        $sections[] = Callout::make(trans('overseer::overseer.config.no_match'))
            ->info()
            ->visible(fn () => !$this->anyVisible($entries));

        return $tab
            ->badge(count(array_filter($entries, fn ($entry) => !($entry['advanced'] ?? false))))
            ->schema($sections);
    }

    /**
     * The settings to show for a source: the curated ones the server has, then the rest.
     *
     * @return array<string, array<string, mixed>>
     */
    private function entriesFor(string $source): array
    {
        $entries = array_diff_key(
            array_intersect_key(ConfigSchema::entries($source), $this->original[$source] ?? []),
            array_flip($this->advancedKeys[$source] ?? []),
        );

        foreach ($entries as $key => $entry) {
            // Keep a value we don't know about selectable instead of silently changing it.
            $current = (string) $this->original[$source][$key];
            if ($entry['type'] === 'enum' && $current !== '' && !array_key_exists($current, $entry['options'])) {
                $entries[$key]['options'][$current] = $current;
            }
        }

        foreach ($this->advancedKeys[$source] ?? [] as $key) {
            $entries[$key] = ConfigSchema::advancedEntry($key);
        }

        return $entries;
    }

    private function field(string $source, string $key, array $entry): Field
    {
        $name = "$source." . ConfigSchema::fieldName($key);

        $field = match ($entry['type']) {
            'bool' => Toggle::make($name)->live(),
            'int' => TextInput::make($name)
                ->numeric()
                ->integer()
                ->minValue($entry['min'] ?? null)
                ->maxValue($entry['max'] ?? null)
                ->suffix($entry['unit'] ?? null)
                ->live(onBlur: true),
            'range' => Slider::make($name)
                ->markAsRequired(false)
                ->range($entry['min'], $entry['max'])
                ->step(1)
                ->tooltips()
                ->live(),
            'enum' => Select::make($name)
                ->options($entry['options'])
                ->selectablePlaceholder(false)
                ->live(),
            'password' => TextInput::make($name)
                ->password()
                ->revealable()
                ->autocomplete('new-password')
                ->placeholder(trans('overseer::overseer.config.unchanged'))
                ->live(onBlur: true),
            default => TextInput::make($name)
                ->maxLength($entry['max'] ?? 1000)
                ->placeholder($entry['placeholder'] ?? null)
                ->live(onBlur: true),
        };

        if ($entry['type'] === 'range' && isset($entry['unit'])) {
            $entry['help'] .= ' (' . $entry['min'] . '–' . $entry['max'] . ' ' . $entry['unit'] . ')';
        }

        // Title and description on the left, the input on the right.
        return $field
            ->inlineLabel()
            ->label(new HtmlString(sprintf(
                '<span class="us-config-title" title="%s">%s</span><span class="us-config-help">%s</span>',
                e($key),
                e($entry['title']),
                e($entry['help']),
            )))
            ->hintActions(array_filter([$this->resetAction($source, $key, $entry)]))
            ->disabled(!$this->canEdit())
            ->hidden(fn () => !$this->isVisible($key, $entry));
    }

    /** An undo icon that puts a setting back to its vanilla default, after a confirmation. */
    private function resetAction(string $source, string $key, array $entry): ?Action
    {
        if (!array_key_exists('default', $entry) || $entry['type'] === 'password' || !$this->canEdit()) {
            return null;
        }

        $field = ConfigSchema::fieldName($key);
        $default = $entry['default'];
        $shown = match (true) {
            is_bool($default) => trans('overseer::overseer.config.' . ($default ? 'on' : 'off')),
            $entry['type'] === 'enum' => $entry['options'][(string) $default] ?? (string) $default,
            $default === '' => trans('overseer::overseer.config.empty'),
            default => (string) $default . (isset($entry['unit']) ? ' ' . $entry['unit'] : ''),
        };

        return Action::make('reset_' . md5("$source.$key"))
            ->label(trans('overseer::overseer.config.reset_tooltip', ['value' => $shown]))
            ->iconButton()
            ->icon('tabler-arrow-back-up')
            ->color('gray')
            ->tooltip(trans('overseer::overseer.config.reset_tooltip', ['value' => $shown]))
            ->visible(fn () => !ConfigSchema::isDefault($entry, $this->data[$source][$field] ?? null))
            ->requiresConfirmation()
            ->modalIcon('tabler-arrow-back-up')
            ->modalHeading(trans('overseer::overseer.config.reset_heading', ['setting' => $entry['title']]))
            ->modalDescription(trans('overseer::overseer.config.reset_help', ['value' => $shown]))
            ->modalSubmitActionLabel(trans('overseer::overseer.config.reset'))
            ->action(fn () => $this->data[$source][$field] = $default);
    }

    private function isVisible(string $key, array $entry): bool
    {
        if (($entry['advanced'] ?? false) && !($this->data['_advanced'] ?? false)) {
            return false;
        }

        return ConfigSchema::matches($key, $entry, $this->data['_search'] ?? '');
    }

    private function anyVisible(array $entries): bool
    {
        foreach ($entries as $key => $entry) {
            if ($this->isVisible($key, $entry)) {
                return true;
            }
        }

        return false;
    }

    private function canEdit(): bool
    {
        return Permission::allows(Permission::CONFIG_EDIT, $this->getRecord());
    }

    /**
     * Settings whose form value differs from what was loaded. A value that fails
     * its checks counts as changed and holds the error message instead.
     *
     * @return array<string, array<string, array{entry: array<string, mixed>, old: ?string, new: ?string, error: ?string}>>
     */
    private function changes(): array
    {
        $changes = [];

        foreach ($this->original as $source => $values) {
            foreach ($this->entriesFor($source) as $key => $entry) {
                $current = $this->data[$source][ConfigSchema::fieldName($key)] ?? null;

                if ($entry['type'] === 'password') {
                    if (filled(trim((string) $current))) {
                        $error = match (true) {
                            (bool) preg_match('/[\x00-\x1F\x7F\s]/', (string) $current) => trans('overseer::overseer.config.password_spaces'),
                            mb_strlen((string) $current) > 100 => trans('overseer::overseer.config.password_long'),
                            default => null,
                        };
                        $changes[$source][$key] = ['entry' => $entry, 'old' => null, 'new' => $error ? null : (string) $current, 'error' => $error];
                    }

                    continue;
                }

                // Untouched: don't re-check it, so a value outside our limits that was already in the file stays as it is.
                $old = ConfigSchema::plain($values[$key]);
                if (ConfigSchema::plain($current) === $old) {
                    continue;
                }

                try {
                    $new = ConfigSchema::toFile($entry, $current);
                    $error = null;
                } catch (InvalidArgumentException $exception) {
                    $new = null;
                    $error = $exception->getMessage();
                }

                if ($new !== $old) {
                    $changes[$source][$key] = ['entry' => $entry, 'old' => $old, 'new' => $new, 'error' => $error];
                }
            }
        }

        return $changes;
    }

    private function changeCount(): int
    {
        return array_sum(array_map('count', $this->changes()));
    }

    /**
     * Pelican's form page wraps everything in a form that calls save() on
     * submit, and Filament draws action modals inside it, so confirming any
     * modal (Reset, for one) would also save. Saving only happens from the
     * Save buttons in the header, after the review modal.
     */
    public function save(): void {}

    /** Saves every changed setting. Returns whether all of them saved. */
    private function saveSettings(): bool
    {
        if (!$this->canEdit()) {
            Notification::make()->title(trans('overseer::overseer.config.no_permission'))->danger()->send();

            return false;
        }

        $changes = $this->changes();

        foreach ($changes as $settings) {
            foreach ($settings as $change) {
                if ($change['error'] !== null) {
                    Notification::make()->title(trans('overseer::overseer.config.invalid'))->body($change['error'])->danger()->send();

                    return false;
                }
            }
        }

        if ($changes === []) {
            return false;
        }

        $saved = 0;
        $failed = false;
        $restart = false;

        foreach ($changes as $source => $settings) {
            try {
                $restart = app(ConfigFiles::class)->save(
                    $this->getRecord(),
                    $source,
                    array_map(fn (array $change) => $change['new'], $settings),
                    array_map(fn (array $change) => $change['entry'], $settings),
                ) || $restart;
            } catch (Exception $exception) {
                report($exception);
                Notification::make()
                    ->title(trans('overseer::overseer.config.failed', ['source' => ConfigSchema::source($source)['title']]))
                    ->body($exception->getMessage())
                    ->danger()
                    ->persistent()
                    ->send();
                $failed = true;

                continue;
            }

            // What was saved is the new starting point.
            foreach ($settings as $key => $change) {
                $field = ConfigSchema::fieldName($key);
                if ($change['entry']['type'] === 'password') {
                    $this->data[$source][$field] = '';
                } else {
                    $this->original[$source][$key] = ConfigSchema::fromFile($change['entry'], $change['new']);
                    $this->data[$source][$field] = $this->original[$source][$key];
                }
                $saved++;
            }
        }

        if ($saved === 0) {
            return false;
        }

        $this->restartNeeded = $this->restartNeeded || $restart;
        if ($restart) {
            cache()->put("overseer:restart-needed:{$this->getRecord()->uuid}", true, now()->addDay());
        }

        Notification::make()
            ->title(trans_choice('overseer::overseer.config.saved', $saved, ['count' => $saved]))
            ->body(implode(' ', array_filter([
                isset($changes['server']) || isset($changes['paper']) ? trans('overseer::overseer.config.saved_backup', ['dir' => ConfigFiles::BACKUP_DIR]) : null,
                $restart ? trans('overseer::overseer.config.saved_restart') : null,
            ])) ?: null)
            ->success()
            ->send();

        return !$failed;
    }

    private function hasInvalidChanges(): bool
    {
        foreach ($this->changes() as $settings) {
            foreach ($settings as $change) {
                if ($change['error'] !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Whether a change touches how Overseer reaches RCON. */
    private function changesRcon(): bool
    {
        return (bool) array_intersect(array_keys($this->changes()['server'] ?? []), ['enable-rcon', 'rcon.port', 'rcon.password']);
    }

    /** How many changed settings only apply after a restart. */
    private function restartChangeCount(): int
    {
        $count = 0;
        foreach ($this->changes() as $source => $settings) {
            foreach ($settings as $change) {
                $count += $source !== 'rules' && ($change['entry']['restart'] ?? false) ? 1 : 0;
            }
        }

        return $count;
    }

    public function discard(): void
    {
        foreach ($this->original as $source => $values) {
            foreach ($this->entriesFor($source) as $key => $entry) {
                $this->data[$source][ConfigSchema::fieldName($key)] = $entry['type'] === 'password' ? '' : $values[$key];
            }
        }
    }

    private function diff(): HtmlString
    {
        $rows = '';

        foreach ($this->changes() as $source => $settings) {
            $format = ConfigSchema::source($source)['format'];
            $rows .= '<div style="margin-top:.75rem;font-weight:600">' . e(ConfigSchema::source($source)['title']) . '</div>';

            foreach ($settings as $key => $change) {
                $line = fn (?string $value) => e(match ($format) {
                    'properties' => "$key=$value",
                    'yaml' => "$key: $value",
                    default => "gamerule $key $value",
                });

                $secret = $change['entry']['type'] === 'password';
                $old = $secret ? '••••••' : $change['old'];
                $new = $secret ? '••••••' : $change['new'];

                if ($change['error'] !== null) {
                    $rows .= '<div style="margin-top:.35rem;font-size:.8rem;color:inherit;opacity:.75">' . e($change['entry']['title']) . '</div>'
                        . '<div style="font-size:.8rem;padding:.15rem .5rem;border-radius:.25rem;background:rgba(239,68,68,.12);color:rgb(220,38,38)">' . e($change['error']) . '</div>';

                    continue;
                }

                $rows .= '<div style="margin-top:.35rem;font-size:.8rem;color:inherit;opacity:.75">' . e($change['entry']['title']) . '</div>'
                    . '<div style="font-family:ui-monospace,monospace;font-size:.8rem;padding:.15rem .5rem;border-radius:.25rem;background:rgba(239,68,68,.12)">- ' . $line($old) . '</div>'
                    . '<div style="font-family:ui-monospace,monospace;font-size:.8rem;padding:.15rem .5rem;border-radius:.25rem;background:rgba(34,197,94,.14)">+ ' . $line($new) . '</div>';
            }
        }

        return new HtmlString($rows);
    }

    private function restart(): void
    {
        $server = $this->getRecord();

        try {
            app(DaemonServerRepository::class)->setServer($server)->power('restart');
            Activity::event('server:power.restart')->log();
            $this->restartNeeded = false;
            cache()->forget("overseer:restart-needed:{$server->uuid}");

            Notification::make()->title(trans('overseer::overseer.config.restarting'))->success()->send();
        } catch (Exception $exception) {
            report($exception);
            Notification::make()->title(trans('overseer::overseer.config.restart_failed'))->body($exception->getMessage())->danger()->send();
        }
    }
}
