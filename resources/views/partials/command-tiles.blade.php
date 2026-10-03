{{--
    Compact buttons for one-click commands. Each mounts the page action with
    the same name, and only that button is disabled while its action runs.
--}}
<div class="us-tiles-grid" style="--us-tile-count: {{ count($tiles) }}">
    @foreach ($tiles as $tile)
        <button
            type="button"
            class="us-tile-btn"
            title="{{ $tile['command'] !== '' ? '/' . $tile['command'] : $tile['label'] }}"
            x-data="{ busy: false }"
            x-on:click="busy = true; $wire.mountAction('{{ $tile['action'] }}').finally(() => (busy = false))"
            x-bind:disabled="busy"
        >
            <x-filament::icon :icon="$tile['icon']" class="us-tile-icon" style="color: {{ $tile['color'] }}" />
            <span class="us-tile-title">{{ $tile['label'] }}</span>
        </button>
    @endforeach
</div>
