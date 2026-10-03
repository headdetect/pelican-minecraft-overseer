<x-filament-panels::page>
    {{ $this->form }}

    @if (\Headdetect\Overseer\Services\Discord\DiscordApi::configured())
        {{ $this->table }}
    @endif
</x-filament-panels::page>
