@php
    use Headdetect\Overseer\Services\ConsoleService;

    $state = app(ConsoleService::class)->rconState(\Filament\Facades\Filament::getTenant());
@endphp

@if (in_array($state, [ConsoleService::RCON_OFF, ConsoleService::RCON_UNREACHABLE], true))
    <x-filament::callout
        color="warning"
        icon="tabler-alert-triangle"
        :heading="trans('overseer::overseer.rcon.' . $state . '.title')"
        :description="trans('overseer::overseer.rcon.' . $state . '.body')"
    />
@endif
