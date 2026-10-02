@php
    $config = $this->mapConfig();
    $setup = $this->statusMessage();
    $can = $this->abilities();
@endphp

<x-filament-panels::page>
    @assets
        <style>{!! file_get_contents(plugin_path('overseer', 'resources/map/live-map.css')) !!}</style>
        <style>{!! file_get_contents(plugin_path('overseer', 'resources/map/overview.css')) !!}</style>
        <script>{!! file_get_contents(plugin_path('overseer', 'resources/map/live-map.js')) !!}</script>
    @endassets

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

    <div class="us-stats" wire:poll.15s>
        <div class="us-stat">
            <div class="us-stat-label">{{ trans('overseer::overseer.overview.game_time') }}</div>
            @if ($stats['time'])
                <div class="us-stat-value">{{ $stats['time']['clock'] }}</div>
                <div class="us-stat-sub">{{ trans('overseer::overseer.overview.day', ['day' => number_format($stats['time']['day'])]) }} · {{ trans('overseer::overseer.overview.phases.' . $stats['time']['phase']) }}</div>
            @else
                <div class="us-stat-value is-empty">{{ $none }}</div>
                <div class="us-stat-sub">{{ $stats['running'] ? trans('overseer::overseer.overview.needs_rcon') : trans('overseer::overseer.overview.offline') }}</div>
            @endif
        </div>

        <div class="us-stat">
            <div class="us-stat-label">{{ trans('overseer::overseer.overview.minecraft') }}</div>
            <div class="us-stat-value {{ $stats['version'] ? '' : 'is-empty' }}">{{ $stats['version'] ?? $none }}</div>
            <div class="us-stat-sub">
                @if ($r['uptime'] !== null)
                    {{ trans('overseer::overseer.overview.uptime', ['time' => \Headdetect\Overseer\Support\ServerStats::uptime($r['uptime'])]) }}
                @else
                    {{ trans('overseer::overseer.overview.offline') }}
                @endif
            </div>
        </div>

        <div class="us-stat">
            <div class="us-stat-label">{{ trans('overseer::overseer.overview.modpack') }}</div>
            @if ($stats['modpack'])
                <div class="us-stat-value us-stat-name" title="{{ $stats['modpack']['name'] }}">
                    @if ($stats['modpack']['url'])
                        <a href="{{ $stats['modpack']['url'] }}" target="_blank" rel="noopener noreferrer">{{ $stats['modpack']['name'] }}</a>
                    @else
                        {{ $stats['modpack']['name'] }}
                    @endif
                </div>
                <div class="us-stat-sub">
                    {{ $stats['modpack']['version'] ?? trans('overseer::overseer.overview.unknown_version') }}
                    @if ($stats['modpack']['url'])
                        · <a href="{{ $stats['modpack']['url'] }}" target="_blank" rel="noopener noreferrer">{{ trans('overseer::overseer.overview.providers.' . $stats['modpack']['provider']) }}</a>
                    @endif
                </div>
            @else
                <div class="us-stat-value is-empty">{{ $none }}</div>
                <div class="us-stat-sub">{{ trans('overseer::overseer.overview.no_modpack') }}</div>
            @endif
        </div>

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
    </div>

    @if ($setup)
        <x-filament::section icon="tabler-map-off" icon-color="warning" :heading="$setup['title']" :description="$setup['body']" compact>
            <x-filament::button wire:click="checkAgain" color="gray" size="sm" icon="tabler-refresh">
                {{ trans('overseer::overseer.map.check_again') }}
            </x-filament::button>
        </x-filament::section>
    @endif

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
                x-on:pointerup="onUp()"
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

                <div class="us-chip us-live" aria-live="polite">
                    <span class="us-led" :class="{ 'is-stale': state !== 'live' }"></span>
                    <span x-show="state === 'live'">{{ trans('overseer::overseer.map.live', ['seconds' => $config['refresh']]) }}</span>
                    <span x-show="state === 'loading'">{{ trans('overseer::overseer.map.loading') }}</span>
                    <span x-show="state === 'stale'" x-cloak>{{ trans('overseer::overseer.map.stale') }}</span>
                </div>
                <div class="us-chip us-coords" x-show="coords" x-text="coords"></div>

                <template x-if="pop && selectedPlayer">
                    <div class="us-pop" :style="`left:${pop.left}px;top:${pop.top}px`" x-on:pointerdown.stop>
                        <div class="us-pop-head">
                            <img :src="head(selectedPlayer.name)" alt="">
                            <div>
                                <strong x-text="selectedPlayer.name"></strong>
                                <span class="us-badge" x-show="selectedPlayer.op">OP</span>
                                <div class="us-row-where" x-show="selectedPlayer.health !== null" x-text="`${selectedPlayer.health} / 20 {{ trans('overseer::overseer.map.health') }}`"></div>
                            </div>
                        </div>
                        <div class="us-pop-where" x-text="`${worldLabel(selectedPlayer.world)} · ${selectedPlayer.x}, ${selectedPlayer.y ?? '?'}, ${selectedPlayer.z}`"></div>
                        <div class="us-pop-actions">
                            @if ($can['kick'])
                                <x-filament::button size="xs" color="warning" x-on:click="act('kick', selectedPlayer.name)">{{ trans('overseer::overseer.players.actions.kick') }}</x-filament::button>
                            @endif
                            @if ($can['ban'])
                                <x-filament::button size="xs" color="danger" x-on:click="act('ban', selectedPlayer.name)">{{ trans('overseer::overseer.players.actions.ban') }}</x-filament::button>
                            @endif
                            @if ($can['op'])
                                <x-filament::button size="xs" color="gray" x-show="!selectedPlayer.op" x-on:click="act('op', selectedPlayer.name)">{{ trans('overseer::overseer.players.actions.op') }}</x-filament::button>
                                <x-filament::button size="xs" color="gray" x-show="selectedPlayer.op" x-on:click="act('deop', selectedPlayer.name)">{{ trans('overseer::overseer.players.actions.deop') }}</x-filament::button>
                            @endif
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <x-filament::section :heading="trans('overseer::overseer.map.online')" compact>
            <x-slot name="afterHeader">
                <x-filament::badge color="success" :tooltip="trans('overseer::overseer.map.online_count')"><span x-text="players.length">0</span></x-filament::badge>
            </x-slot>

            <div class="us-list">
                <template x-for="r in recent" :key="r.name">
                    <div>
                        <template x-if="live(r.name)">
                            <button type="button" class="us-row" :class="{ 'is-selected': selected === r.name }" x-on:click="focus(live(r.name))">
                                <img :src="head(r.name)" alt="">
                                <div style="min-width: 0">
                                    <div class="us-row-name"><span class="us-dot" aria-hidden="true"></span><span x-text="r.name"></span><span class="us-badge" x-show="r.op">OP</span></div>
                                    <div class="us-row-where" x-text="`${worldLabel(live(r.name).world)} · ${live(r.name).x}, ${live(r.name).z}`"></div>
                                </div>
                            </button>
                        </template>
                        <template x-if="!live(r.name)">
                            <div class="us-row is-offline">
                                <img :src="head(r.name)" alt="">
                                <div style="min-width: 0">
                                    <div class="us-row-name"><span x-text="r.name"></span><span class="us-badge" x-show="r.op">OP</span></div>
                                    <div class="us-row-when" x-text="r.online ? '{{ trans('overseer::overseer.map.online_now') }}' : (r.last_seen ? ago(r.last_seen) : '{{ trans('overseer::overseer.map.never') }}')"></div>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>
                <div class="us-empty" x-show="recent.length === 0 && state === 'live'">{{ trans('overseer::overseer.map.nobody') }}</div>
                <div class="us-empty" x-show="state === 'stale'" x-cloak>
                    {{ $config['mode'] === 'squaremap' ? trans('overseer::overseer.map.no_positions_squaremap') : trans('overseer::overseer.map.no_positions_rcon') }}
                </div>
            </div>

            <p class="us-note">
                {{ $config['mode'] === 'squaremap' ? trans('overseer::overseer.map.source_squaremap') : trans('overseer::overseer.map.source_rcon') }}
            </p>
        </x-filament::section>
    </div>
</x-filament-panels::page>
