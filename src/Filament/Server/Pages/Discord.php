<?php

namespace Headdetect\Overseer\Filament\Server\Pages;

use App\Models\Server;
use App\Traits\Filament\BlockAccessInConflict;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Headdetect\Overseer\Filament\Server\Clusters\Overseer;
use Headdetect\Overseer\Models\AuditEntry;
use Headdetect\Overseer\Models\DiscordSetting;
use Headdetect\Overseer\Services\Discord\DiscordApi;
use Headdetect\Overseer\Services\PlayerService;
use Headdetect\Overseer\Support\Permission;
use Headdetect\Overseer\Support\ServerAddress;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Links the server to a Discord, sets what /info shows, and lists every
 * /whitelist from Discord. A Filament page, unlike the React tabs, since
 * it's a form and a table.
 *
 * @property Schema $form
 */
class Discord extends Page implements HasForms, HasTable
{
    use BlockAccessInConflict;
    use InteractsWithForms;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-brand-discord';

    protected static ?string $slug = 'discord';

    protected static ?string $cluster = Overseer::class;

    protected static ?int $navigationSort = 6;

    protected string $view = 'overseer::discord';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** Why the guild list is empty, when Discord didn't answer. */
    public ?string $guildError = null;

    /** @var array<string, string>|null guild id => name, loaded once per request */
    private ?array $guilds = null;

    /** @var string[]|null names on the whitelist, loaded once per request */
    private ?array $whitelist = null;

    public static function canAccess(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return Permission::allows(Permission::DISCORD, $server) && parent::canAccess();
    }

    public static function getNavigationLabel(): string
    {
        return trans('overseer::overseer.discord.title');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        $setting = DiscordSetting::for($this->server());

        $this->form->fill([
            'guild_id' => $setting->guild_id,
            'address' => $setting->address,
            'port' => $setting->port,
            'info' => $setting->info,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $text = fn (string $key, array $replace = []) => trans("overseer::overseer.discord.page.$key", $replace);

        if (!DiscordApi::configured()) {
            return $schema->components([
                Section::make($text('not_configured'))
                    ->description($text('not_configured_help'))
                    ->icon('tabler-plug-off'),
            ]);
        }

        $server = $this->server();

        return $schema
            ->statePath('data')
            ->components([
                Section::make($text('connection'))
                    ->description($text('connection_help'))
                    ->schema([
                        Select::make('guild_id')
                            ->label($text('guild'))
                            ->options(fn () => $this->guilds())
                            ->helperText(fn () => $this->guildError ?? $text('guild_help'))
                            ->hint(fn (?string $state) => $state && $this->linkedElsewhere($state) ? $text('linked_elsewhere') : null)
                            ->hintColor('warning')
                            ->live(),
                        Actions::make([
                            Action::make('invite')
                                ->label($text('invite'))
                                ->icon('tabler-external-link')
                                ->color('gray')
                                ->url(DiscordApi::inviteUrl(), shouldOpenInNewTab: true),
                            Action::make('register')
                                ->label($text('register'))
                                ->icon('tabler-plug-connected')
                                ->disabled(fn (Get $get) => blank($get('guild_id')))
                                ->action(fn () => $this->register()),
                        ]),
                    ]),
                Section::make($text('join'))
                    ->description($text('join_help'))
                    ->columns(3)
                    ->schema([
                        TextInput::make('address')
                            ->label($text('address'))
                            ->placeholder(ServerAddress::publicHost($server))
                            ->helperText($text('address_help', ['default' => ServerAddress::publicHost($server)]))
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->columnSpan(2),
                        TextInput::make('port')
                            ->label($text('port'))
                            ->placeholder((string) ServerAddress::publicPort($server))
                            ->helperText($text('port_help', ['default' => ServerAddress::publicPort($server)]))
                            ->integer()
                            ->minValue(1)
                            ->maxValue(65535)
                            ->live(onBlur: true),
                        Textarea::make('info')
                            ->label($text('info'))
                            ->helperText($text('info_help'))
                            ->maxLength(1000)
                            ->autosize()
                            ->columnSpanFull(),
                        Text::make(fn (Get $get) => $text('preview') . ': ' . ServerAddress::join(
                            filled($get('address')) ? (string) $get('address') : ServerAddress::publicHost($server),
                            filled($get('port')) ? (int) $get('port') : ServerAddress::publicPort($server),
                        ))
                            ->columnSpanFull(),
                        Actions::make([
                            Action::make('save')
                                ->label($text('save'))
                                ->action(fn () => $this->save()),
                        ])->columnSpanFull(),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        $text = fn (string $key) => trans("overseer::overseer.discord.page.$key");

        return $table
            ->heading($text('log'))
            ->description($text('log_help'))
            ->query(fn () => AuditEntry::query()
                ->where('server_id', $this->server()->id)
                ->whereNotNull('discord_user_id')
                ->whereIn('action', ['whitelist', 'whitelist_rejected']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label($text('when'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
                TextColumn::make('discord_name')
                    ->label($text('user'))
                    ->formatStateUsing(fn (string $state) => "@$state")
                    ->searchable(),
                TextColumn::make('target')
                    ->label($text('name'))
                    ->searchable(),
                TextColumn::make('result')
                    ->label($text('result'))
                    ->badge()
                    ->state(fn (AuditEntry $record) => $record->action === 'whitelist' ? $text('added') : $record->response)
                    ->color(fn (AuditEntry $record) => $record->action === 'whitelist' ? 'success' : 'gray'),
                IconColumn::make('listed')
                    ->label($text('listed'))
                    ->state(fn (AuditEntry $record) => in_array($record->target, $this->whitelist(), true))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('discord_user_id')
                    ->label($text('user'))
                    ->options(fn () => AuditEntry::query()
                        ->where('server_id', $this->server()->id)
                        ->whereNotNull('discord_user_id')
                        ->pluck('discord_name', 'discord_user_id')
                        ->map(fn (string $name) => "@$name")
                        ->all()),
                SelectFilter::make('result')
                    ->label($text('result'))
                    ->options(['whitelist' => $text('added'), 'whitelist_rejected' => trans('overseer::overseer.discord.page.rejected')])
                    ->query(fn (Builder $query, array $data) => filled($data['value']) ? $query->where('action', $data['value']) : $query),
            ])
            ->emptyStateHeading($text('empty'))
            ->emptyStateIcon('tabler-brand-discord');
    }

    public function register(): void
    {
        $guildId = (string) ($this->data['guild_id'] ?? '');
        $guilds = $this->guilds();

        try {
            app(DiscordApi::class)->registerCommands($guildId);
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()
                ->title(trans('overseer::overseer.discord.page.register_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        // One server per Discord: registering here takes the guild from any other server.
        DiscordSetting::query()
            ->where('guild_id', $guildId)
            ->where('server_id', '!=', $this->server()->id)
            ->update(['guild_id' => null, 'guild_name' => null]);

        $setting = DiscordSetting::for($this->server());
        $setting->fill(['guild_id' => $guildId, 'guild_name' => $guilds[$guildId] ?? null])->save();

        Notification::make()
            ->title(trans('overseer::overseer.discord.page.registered'))
            ->body($guilds[$guildId] ?? null)
            ->success()
            ->send();
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $setting = DiscordSetting::for($this->server());
        $setting->fill([
            'address' => filled($data['address'] ?? null) ? trim($data['address']) : null,
            'port' => filled($data['port'] ?? null) ? (int) $data['port'] : null,
            'info' => filled($data['info'] ?? null) ? trim($data['info']) : null,
        ])->save();

        Notification::make()->title(trans('overseer::overseer.discord.page.saved'))->success()->send();
    }

    /** @return array<string, string> */
    private function guilds(): array
    {
        if ($this->guilds !== null) {
            return $this->guilds;
        }

        try {
            $this->guildError = null;

            return $this->guilds = app(DiscordApi::class)->guilds();
        } catch (Throwable $exception) {
            report($exception);
            $this->guildError = trans('overseer::overseer.discord.page.guilds_failed', ['error' => $exception->getMessage()]);

            return $this->guilds = [];
        }
    }

    private function linkedElsewhere(string $guildId): bool
    {
        return DiscordSetting::query()
            ->where('guild_id', $guildId)
            ->where('server_id', '!=', $this->server()->id)
            ->exists();
    }

    /** @return string[] */
    private function whitelist(): array
    {
        return $this->whitelist ??= app(PlayerService::class)->whitelist($this->server());
    }

    private function server(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }
}
