{{--
    The root of the Overseer React app. Livewire leaves it alone (wire:ignore).
    The script mounts the app here, and again after each wire:navigate visit.
--}}
<div>
    @assets
        <link rel="stylesheet" href="{{ $style }}">
        <script src="{{ $script }}" defer></script>
    @endassets

    <div id="overseer-app" wire:ignore data-boot="{{ json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) }}"></div>
</div>
