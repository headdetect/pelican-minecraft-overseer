{{--
    The Quick actions card in the Overview's right column.
    $withClock shows the game time, which needs the map's Alpine data.
--}}
@php($tiles = $this->commandTiles())

@if ($this->canRunWorldCommands())
    <x-filament::section :heading="trans('overseer::overseer.commands.quick')" compact>
        <div class="us-qa">
            <div class="us-qa-head">
                <span class="us-stat-label">{{ trans('overseer::overseer.overview.game_time') }}</span>
                @if ($withClock)
                    <template x-if="time">
                        <span class="us-time">
                            <span class="us-time-icon" x-text="phaseIcon(time.phase)" :title="cfg.labels.phases[time.phase]" aria-hidden="true"></span>
                            <span class="us-qa-clock" x-text="time.clock"></span>
                            <span class="us-stat-sub" x-text="cfg.labels.day.replace(':day', time.day.toLocaleString())"></span>
                        </span>
                    </template>
                @endif
            </div>
            @include('overseer::partials.command-tiles', ['tiles' => $tiles['time']])

            <div class="us-qa-head">
                <span class="us-stat-label">{{ trans('overseer::overseer.commands.weather.title') }}</span>
            </div>
            @include('overseer::partials.command-tiles', ['tiles' => $tiles['weather']])

            @if (count($tiles['players']))
                <div class="us-qa-head">
                    <span class="us-stat-label">{{ trans('overseer::overseer.commands.players') }}</span>
                </div>
                @include('overseer::partials.command-tiles', ['tiles' => $tiles['players']])
            @endif
        </div>
    </x-filament::section>
@endif
