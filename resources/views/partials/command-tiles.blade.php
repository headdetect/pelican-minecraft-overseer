{{-- Compact buttons for one-click commands. Each mounts the page action with the same name. --}}
<div class="us-tiles-grid" style="--us-tile-count: {{ count($tiles) }}">
    @foreach ($tiles as $tile)
        <button
            type="button"
            class="us-tile-btn"
            title="/{{ $tile['command'] }}"
            wire:click="mountAction('{{ $tile['action'] }}')"
            wire:loading.attr="disabled"
            wire:target="mountAction('{{ $tile['action'] }}')"
        >
            <x-filament::icon :icon="$tile['icon']" class="us-tile-icon" style="color: {{ $tile['color'] }}" />
            <span class="us-tile-title">{{ $tile['label'] }}</span>
        </button>
    @endforeach
</div>
