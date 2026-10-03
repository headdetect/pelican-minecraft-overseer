@php
    $chunky = $this->chunky();
@endphp

<x-filament-panels::page>
    <x-filament::section icon="tabler-grid-dots" :heading="trans('overseer::overseer.tools.pregen.title')" :description="trans('overseer::overseer.tools.pregen.help')">
        <div x-data x-on:overseer-refresh.window="$wire.$refresh()" class="flex flex-col gap-4">
            @if (!$chunky['running'])
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ trans($chunky['starting'] ? 'overseer::overseer.tools.starting' : 'overseer::overseer.tools.offline') }}</p>
            @elseif ($chunky['installed'] === null)
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ trans('overseer::overseer.tools.pregen.no_rcon') }}</p>
            @elseif ($chunky['installed'] === false)
                <div class="text-sm text-gray-600 dark:text-gray-300">
                    {{ trans('overseer::overseer.tools.pregen.missing') }}
                    <a href="https://modrinth.com/plugin/chunky" target="_blank" rel="noopener noreferrer" class="text-primary-600 underline dark:text-primary-400">modrinth.com/plugin/chunky</a>
                </div>
            @elseif ($chunky['installed'] === true)
                @forelse ($chunky['tasks'] as $task)
                    <div>
                        <div class="flex items-baseline justify-between gap-4 text-sm">
                            <span class="font-medium">{{ $task['world'] }}</span>
                            <span class="tabular-nums text-gray-500 dark:text-gray-400">
                                {{ trans('overseer::overseer.tools.pregen.progress', ['chunks' => number_format($task['chunks']), 'percent' => number_format($task['percent'], 1)]) }}
                                @if ($task['eta'] && trim($task['eta'], '0:') !== '')
                                    · {{ trans('overseer::overseer.tools.pregen.eta', ['eta' => $task['eta']]) }}
                                @endif
                            </span>
                        </div>
                        {{-- Inline styles, because Tailwind doesn't scan plugin views. --}}
                        <div role="progressbar" aria-valuenow="{{ $task['percent'] }}" aria-valuemin="0" aria-valuemax="100" style="margin-top: 0.5rem; height: 0.5rem; overflow: hidden; border-radius: 9999px; background: rgb(127 127 127 / 0.2)">
                            <div style="height: 100%; width: {{ $task['percent'] }}%; border-radius: 9999px; background: var(--primary-500, #3b82f6); transition: width 0.5s"></div>
                        </div>
                    </div>
                @empty
                    @if (!$chunky['saved'])
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ trans('overseer::overseer.tools.pregen.idle') }}</p>
                    @endif
                @endforelse

                @foreach ($chunky['saved'] as $task)
                    <p class="text-sm">
                        <span class="font-medium">{{ $task['world'] }}</span>
                        <span class="text-gray-500 dark:text-gray-400">· {{ $task['percent'] !== null
                            ? trans('overseer::overseer.tools.pregen.paused_at', ['chunks' => number_format($task['chunks']), 'percent' => number_format($task['percent'], 1)])
                            : trans('overseer::overseer.tools.pregen.paused_chunks', ['chunks' => number_format($task['chunks'])]) }}</span>
                    </p>
                @endforeach

                <div class="flex flex-wrap gap-2">
                    {{ $this->startAction }}
                    @if ($chunky['tasks'])
                        {{ $this->pauseAction }}
                    @endif
                    @if ($chunky['saved'])
                        {{ $this->continueAction }}
                    @endif
                    @if ($chunky['tasks'] || $chunky['saved'])
                        {{ $this->cancelAction }}
                    @endif
                </div>
            @endif
        </div>
    </x-filament::section>

    @if ($this->squaremapReady())
        <x-filament::section icon="tabler-map" :heading="trans('overseer::overseer.tools.render.title')" :description="trans('overseer::overseer.tools.render.help')">
            {{ $this->renderAction }}
        </x-filament::section>
    @endif
</x-filament-panels::page>
