{{-- The Players page filters, as a compact segmented row inside the table's card. --}}
<style>
    .us-filters-bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.75rem 1rem 0; }
    .us-filters-actions { display: flex; gap: 0.5rem; }
    .us-filters { display: inline-flex; flex-wrap: wrap; gap: 2px; padding: 3px; border-radius: 0.6rem; background: rgb(127 127 127 / 0.1); }
    .us-filters button { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.25rem 0.7rem; border-radius: 0.45rem; font-size: 0.8rem; font-weight: 500; color: rgb(107 114 128); }
    .dark .us-filters button { color: rgb(161 161 170); }
    .us-filters button:hover { color: inherit; }
    .us-filters button.is-on { background: #fff; color: rgb(17 24 39); box-shadow: 0 1px 2px rgb(0 0 0 / 0.08); }
    .dark .us-filters button.is-on { background: rgb(255 255 255 / 0.1); color: #fff; }
    .us-filters button:focus-visible { outline: 2px solid var(--primary-500, #3b82f6); outline-offset: 1px; }
    .us-filters .us-count { font-size: 0.7rem; font-variant-numeric: tabular-nums; opacity: 0.7; }
</style>
<div class="us-filters-bar">
<div class="us-filters" role="group" aria-label="{{ trans('overseer::overseer.players.title') }}">
    @foreach ($tabs as $key => $tab)
        <button type="button" wire:click="$set('activeTab', '{{ $key }}')" @class(['is-on' => $active === $key]) aria-pressed="{{ $active === $key ? 'true' : 'false' }}">
            {{ $tab['label'] }}
            @if ($tab['badge'] !== null)
                <span class="us-count">{{ $tab['badge'] }}</span>
            @endif
        </button>
    @endforeach
</div>
<div class="us-filters-actions">
    @foreach ($actions as $action)
        {{ $action }}
    @endforeach
</div>
</div>
