@php
    // The map, stats and players need map-view. Users with only command permissions see the quick actions.
    $config = $this->canSeeMap() ? $this->mapConfig() : null;
    $setup = $this->canSeeMap() ? $this->statusMessage() : null;
    $can = $this->abilities();
@endphp

<x-filament-panels::page>
    @assets
        <style>{!! file_get_contents(plugin_path('overseer', 'resources/map/live-map.css')) !!}</style>
        <style>{!! file_get_contents(plugin_path('overseer', 'resources/map/overview.css')) !!}</style>
        <script>{!! file_get_contents(plugin_path('overseer', 'resources/map/live-map.js')) !!}</script>
    @endassets

    @if ($this->canSeeMap())
    @php
        $stats = $this->stats();
        $r = $stats['resources'];
        $bytes = fn (?int $value) => $value === null ? null : convert_bytes_to_readable($value, 1);
        $percent = fn (?float $used, int $limit) => $used !== null && $limit > 0 ? min(100, round($used / $limit * 100)) : null;
        $cpuPercent = $percent($r['cpu'], $r['cpu_limit']);
        $memoryPercent = $percent($r['memory'], $r['memory_limit']);
        $diskPercent = $percent($r['disk'], $r['disk_limit']);
        $none = trans('overseer::overseer.overview.none');
    @endphp

    <div class="us-stats">
        <div class="us-stat">
            <div class="us-stat-label">{{ trans('overseer::overseer.overview.cpu') }}</div>
            <div class="us-stat-value {{ $r['cpu'] === null ? 'is-empty' : '' }}">{{ $r['cpu'] === null ? $none : number_format($r['cpu'], 1) . '%' }}</div>
            <div class="us-stat-sub">{{ $r['cpu_limit'] > 0 ? trans('overseer::overseer.overview.of', ['limit' => $r['cpu_limit'] . '%']) : trans('overseer::overseer.overview.no_limit') }}</div>
            @if ($cpuPercent !== null)
                <div class="us-bar"><span style="width: {{ $cpuPercent }}%"></span></div>
            @endif
        </div>

        <div class="us-stat">
            <div class="us-stat-label">{{ trans('overseer::overseer.overview.memory') }}</div>
            <div class="us-stat-value {{ $r['memory'] === null ? 'is-empty' : '' }}">{{ $bytes($r['memory']) ?? $none }}</div>
            <div class="us-stat-sub">{{ $r['memory_limit'] > 0 ? trans('overseer::overseer.overview.of', ['limit' => $bytes($r['memory_limit'])]) : trans('overseer::overseer.overview.no_limit') }}</div>
            @if ($memoryPercent !== null)
                <div class="us-bar"><span style="width: {{ $memoryPercent }}%"></span></div>
            @endif
        </div>

        <div class="us-stat">
            <div class="us-stat-label">{{ trans('overseer::overseer.overview.disk') }}</div>
            <div class="us-stat-value {{ $r['disk'] === null ? 'is-empty' : '' }}">{{ $bytes($r['disk']) ?? $none }}</div>
            <div class="us-stat-sub">{{ $r['disk_limit'] > 0 ? trans('overseer::overseer.overview.of', ['limit' => $bytes($r['disk_limit'])]) : trans('overseer::overseer.overview.no_limit') }}</div>
            @if ($diskPercent !== null)
                <div class="us-bar"><span style="width: {{ $diskPercent }}%"></span></div>
            @endif
        </div>

        @if ($this->canRunWorldCommands() || $this->canRunOpsCommands())
            @include('overseer::partials.server-card')
        @endif
    </div>

    @if ($setup)
        <x-filament::section icon="tabler-map-off" icon-color="warning" :heading="$setup['title']" :description="$setup['body']" compact>
            <x-filament::button wire:click="checkAgain" color="gray" size="sm" icon="tabler-refresh">
                {{ trans('overseer::overseer.map.check_again') }}
            </x-filament::button>
        </x-filament::section>
    @endif

    <div class="us-map-wrap">
    <div wire:ignore x-data="overseerLiveMap(@js($config))" class="us-map">
        <div>
            <div
                x-ref="viewport"
                class="us-viewport"
                tabindex="0"
                role="application"
                aria-label="{{ trans('overseer::overseer.map.aria') }}"
                x-on:wheel.prevent="onWheel($event)"
                x-on:pointerdown="onDown($event)"
                x-on:pointermove="onMove($event)"
                x-on:pointerup="onUp($event)"
                x-on:pointercancel="onUp()"
                x-on:pointerleave="coords = ''"
                x-on:keydown="onKey($event)"
            >
                <canvas x-ref="grid" class="us-grid" x-show="cfg.mode === 'grid'"></canvas>
                <div x-ref="tiles" class="us-tiles"></div>
                <div x-ref="pins" class="us-pins"></div>

                <div class="us-controls">
                    <div class="us-seg" role="group" aria-label="{{ trans('overseer::overseer.map.world') }}">
                        <template x-for="w in worlds" :key="w.name">
                            <button type="button" x-text="w.label" :class="{ 'is-on': w.name === world }" :aria-pressed="w.name === world" x-on:click="setWorld(w.name)"></button>
                        </template>
                    </div>
                    <div class="us-seg" role="group" aria-label="{{ trans('overseer::overseer.map.zoom') }}">
                        <button type="button" x-on:click="zoomBy(1)" aria-label="{{ trans('overseer::overseer.map.zoom_in') }}">+</button>
                        <button type="button" x-on:click="zoomBy(-1)" aria-label="{{ trans('overseer::overseer.map.zoom_out') }}">&minus;</button>
                    </div>
                </div>

                <div class="us-chip us-live">
                    <span class="us-led" :class="{ 'is-stale': state === 'stale', 'is-paused': state === 'live' && autoSeconds === 0, 'is-fetching': fetching && autoSeconds > 0 }"></span>
                    <span x-show="state === 'live' && autoSeconds > 0">{{ trans('overseer::overseer.map.live') }}</span>
                    <span x-show="state === 'live' && autoSeconds === 0" x-text="updatedAgo()" x-cloak></span>
                    <span x-show="state === 'loading'">{{ trans('overseer::overseer.map.loading') }}</span>
                    <span x-show="state === 'stale'" x-cloak>{{ trans('overseer::overseer.map.stale') }}</span>
                </div>
                <div class="us-chip us-coords" x-show="coords" x-text="coords"></div>

                <template x-if="point">
                    <div>
                        <span class="us-point-dot" :style="`left:${point.dotX}px;top:${point.dotY}px`" aria-hidden="true"></span>
                        <div class="us-pop us-point" :style="`left:${point.left}px;top:${point.top}px`" x-on:pointerdown.stop role="dialog" aria-label="{{ trans('overseer::overseer.map.point.title') }}">
                            <div class="us-point-coords">
                                <span x-text="`x ${point.x}`"></span>
                                <span x-text="point.loading ? 'y …' : (point.y === null ? 'y ?' : `y ${point.y}`)" :title="point.loading ? @js(trans('overseer::overseer.map.point.finding')) : null"></span>
                                <span x-text="`z ${point.z}`"></span>
                            </div>
                            <div class="us-pop-where" x-text="worldLabel(point.world)"></div>
                            <div class="us-point-note" x-show="point.failed" x-cloak>{{ trans('overseer::overseer.map.point.no_ground') }}</div>
                            <div class="us-point-note" x-show="!point.loading && point.world.includes('nether')" x-cloak>{{ trans('overseer::overseer.map.point.nether') }}</div>
                            <template x-if="players.length">
                                <div class="us-point-tp">
                                    <select x-model="point.player" aria-label="{{ trans('overseer::overseer.map.point.player') }}">
                                        <template x-for="pl in players" :key="pl.name">
                                            <option :value="pl.name" x-text="pl.name"></option>
                                        </template>
                                    </select>
                                    <button type="button" class="us-pop-btn is-primary" x-on:click="teleportHere()" x-bind:disabled="point.loading || point.y === null || point.sending">{{ trans('overseer::overseer.map.point.teleport') }}</button>
                                </div>
                            </template>
                            <div class="us-point-note" x-show="!players.length">{{ trans('overseer::overseer.map.point.nobody') }}</div>
                        </div>
                    </div>
                </template>

                <template x-if="pop && selectedPlayer">
                    <div class="us-pop" :style="`left:${pop.left}px;top:${pop.top}px`" x-on:pointerdown.stop>
                        <div class="us-pop-head">
                            <img :src="head(selectedPlayer?.name)" alt="">
                            <div>
                                <strong x-text="selectedPlayer?.name"></strong>
                                <span class="us-badge" x-show="selectedPlayer?.op">{{ trans('overseer::overseer.map.op') }}</span>
                                <div class="us-row-where" x-show="selectedPlayer?.health !== null" x-text="`${selectedPlayer?.health} / 20 ` + @js(trans('overseer::overseer.map.health'))"></div>
                            </div>
                        </div>
                        <div class="us-pop-where" x-text="`${worldLabel(selectedPlayer?.world)} · ${selectedPlayer?.x}, ${selectedPlayer?.y ?? '?'}, ${selectedPlayer?.z}`"></div>
                        <div class="us-pop-actions">
                            @if ($can['kick'])
                                <button type="button" class="us-pop-btn is-warning" x-on:click="act('kick', selectedPlayer?.name)">{{ trans('overseer::overseer.players.actions.kick') }}</button>
                            @endif
                            @if ($can['ban'])
                                <button type="button" class="us-pop-btn is-danger" x-on:click="act('ban', selectedPlayer?.name)">{{ trans('overseer::overseer.players.actions.ban') }}</button>
                            @endif
                            @if ($can['gamemode'])
                                <button type="button" class="us-pop-btn is-gray" x-on:click="act('gamemode', selectedPlayer?.name)">{{ trans('overseer::overseer.players.actions.gamemode') }}</button>
                            @endif
                            @if ($can['op'])
                                <button type="button" class="us-pop-btn is-gray" x-show="!selectedPlayer?.op" x-on:click="act('op', selectedPlayer?.name)">{{ trans('overseer::overseer.players.actions.op') }}</button>
                                <button type="button" class="us-pop-btn is-gray" x-show="selectedPlayer?.op" x-on:click="act('deop', selectedPlayer?.name)">{{ trans('overseer::overseer.players.actions.deop') }}</button>
                            @endif
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <div class="us-side">

            <x-filament::section :heading="trans('overseer::overseer.chat.title')" compact>
                <div class="us-chat" aria-live="polite">
                    <template x-for="(m, i) in chat" :key="i">
                        <div class="us-chat-line" :class="`is-${m.type}`">
                            <span class="us-chat-time" x-text="m.time.slice(0, 5)" title="{{ trans('overseer::overseer.chat.server_time') }}"></span>
                            <template x-if="m.type === 'chat'">
                                <span><b x-text="m.name"></b> <span x-text="m.text"></span></span>
                            </template>
                            <template x-if="m.type === 'say'">
                                <span><b x-text="`[${m.name}]`"></b> <span x-text="m.text"></span></span>
                            </template>
                            <template x-if="m.type === 'join'">
                                <span x-text="cfg.labels.joined.replace(':name', m.name)"></span>
                            </template>
                            <template x-if="m.type === 'leave'">
                                <span x-text="cfg.labels.left.replace(':name', m.name)"></span>
                            </template>
                        </div>
                    </template>
                    <div class="us-empty" x-show="chat.length === 0">{{ trans('overseer::overseer.chat.empty') }}</div>
                </div>
                @if ($this->canRunOpsCommands())
                    <form class="us-chat-send" x-on:submit.prevent="sendChat()">
                        <input type="text" x-model="draft" maxlength="200" placeholder="{{ trans('overseer::overseer.chat.placeholder') }}" aria-label="{{ trans('overseer::overseer.chat.placeholder') }}" :disabled="sending">
                        <button type="submit" :disabled="sending || !draft.trim()" aria-label="{{ trans('overseer::overseer.chat.send') }}" title="{{ trans('overseer::overseer.chat.send') }}">
                            <x-filament::icon icon="tabler-send" />
                        </button>
                    </form>
                @endif
            </x-filament::section>

            <x-filament::section :heading="trans('overseer::overseer.map.online')" compact>
                <x-slot name="afterHeader">
                    <x-filament::badge color="success"><span x-text="players.length + unmapped.length">0</span></x-filament::badge>
                </x-slot>

                <div class="us-list">
                    <template x-for="p in players" :key="p.name">
                        <button type="button" class="us-row" :class="{ 'is-selected': selected === p.name }" x-on:click="focus(p)" :aria-pressed="selected === p.name">
                            <img :src="head(p.name)" alt="">
                            <div style="min-width: 0">
                                <div class="us-row-name"><span x-text="p.name"></span><span class="us-badge" x-show="p.op">{{ trans('overseer::overseer.map.op') }}</span></div>
                                <div class="us-row-where" x-text="`${worldLabel(p.world)} · ${p.x}, ${p.z}`"></div>
                            </div>
                        </button>
                    </template>
                    <template x-for="u in unmapped" :key="u.name">
                        <div class="us-row is-unmapped">
                            <img :src="head(u.name)" alt="">
                            <div style="min-width: 0">
                                <div class="us-row-name"><span x-text="u.name"></span><span class="us-badge" x-show="u.op">{{ trans('overseer::overseer.map.op') }}</span></div>
                                <div class="us-row-where" x-text="u.dead ? @js(trans('overseer::overseer.map.dead')) : @js(trans('overseer::overseer.map.not_on_map'))"></div>
                            </div>
                        </div>
                    </template>
                    <div class="us-empty" x-show="players.length === 0 && unmapped.length === 0 && state === 'live'">{{ trans('overseer::overseer.map.nobody') }}</div>
                    <div class="us-empty" x-show="state === 'stale'" x-cloak>
                        {{ $config['mode'] === 'squaremap' ? trans('overseer::overseer.map.no_positions_squaremap') : trans('overseer::overseer.map.no_positions_rcon') }}
                    </div>
                </div>
            </x-filament::section>

            @include('overseer::partials.quick-actions', ['withClock' => true])

            <x-filament::section compact>
                <div class="us-stat-label">{{ trans('overseer::overseer.overview.minecraft') }}</div>
                <div class="us-stat-value" :class="{ 'is-empty': !server?.version }" x-text="server?.version ?? @js(trans('overseer::overseer.overview.none'))"></div>
                <div class="us-stat-sub">
                    <template x-if="server?.modpack">
                        <span :title="`${server.modpack.name} ${server.modpack.version ?? ''}`">
                            <a x-show="server.modpack.url" :href="server.modpack.url" target="_blank" rel="noopener noreferrer" x-text="server.modpack.name"></a>
                            <span x-show="!server.modpack.url" x-text="server.modpack.name"></span>
                            <span x-text="server.modpack.version ?? ''"></span>
                        </span>
                    </template>
                    <template x-if="!server?.modpack">
                        <span>{{ trans('overseer::overseer.overview.no_modpack') }}</span>
                    </template>
                </div>
                <div class="us-stat-sub" x-text="server?.uptime ?? @js(trans('overseer::overseer.overview.offline'))"></div>
            </x-filament::section>
        </div>
    </div>
    </div>
    @endif

    @if (!$this->canSeeMap() && ($this->canRunWorldCommands() || $this->canRunOpsCommands()))
        <div class="us-side us-side-alone">
            @include('overseer::partials.server-card')
            @include('overseer::partials.quick-actions', ['withClock' => false])
        </div>
    @endif

    @if ($this->canRunWorldCommands() || $this->canRunOpsCommands())
        <x-filament::section :heading="trans('overseer::overseer.commands.recent.title')" :description="trans('overseer::overseer.commands.recent.help')" compact>
            @php($recentActions = $this->recentActions())
            @if ($recentActions)
                <ul class="us-log">
                    @foreach ($recentActions as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
            @else
                <p class="us-help">{{ trans('overseer::overseer.commands.recent.empty') }}</p>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
