@php
    use Headdetect\Overseer\Services\ConsoleService;

    $server = \Filament\Facades\Filament::getTenant();
    $state = app(ConsoleService::class)->rconState($server);
    $exposed = app(ConsoleService::class)->exposedRconPort($server);
@endphp

@if ($exposed)
    <x-filament::callout
        color="danger"
        icon="tabler-shield-exclamation"
        :heading="trans('overseer::overseer.rcon.exposed.title', ['port' => $exposed])"
        :description="trans('overseer::overseer.rcon.exposed.body', ['port' => $exposed])"
    />
@endif

@if (in_array($state, [ConsoleService::RCON_OFF, ConsoleService::RCON_UNREACHABLE], true))
    <x-filament::callout
        color="warning"
        icon="tabler-alert-triangle"
        :heading="trans('overseer::overseer.rcon.' . $state . '.title')"
        :description="trans('overseer::overseer.rcon.' . $state . '.body')"
    />
@endif
