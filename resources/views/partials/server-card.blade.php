{{--
    The Server card in the Overview's top row: one row of short buttons, so the
    card is as tall as the resource cards. Each button mounts the page action
    with the same name, which runs it or opens its form.
--}}
@php
    $buttons = array_filter([
        $this->canRunWorldCommands() ? ['save', 'tabler-device-floppy', trans('overseer::overseer.commands.short.save'), trans('overseer::overseer.commands.buttons.save')] : null,
        $this->canRunOpsCommands() ? ['broadcast', 'tabler-speakerphone', trans('overseer::overseer.commands.short.broadcast'), trans('overseer::overseer.commands.buttons.broadcast')] : null,
        $this->canRunOpsCommands() ? ['custom', 'tabler-terminal-2', trans('overseer::overseer.commands.short.custom'), trans('overseer::overseer.commands.buttons.custom')] : null,
    ]);
@endphp

<div class="us-stat">
    <div class="us-stat-label">{{ trans('overseer::overseer.commands.server.title') }}</div>
    <div class="us-btn-group" role="group" aria-label="{{ trans('overseer::overseer.commands.server.title') }}">
        @foreach ($buttons as [$action, $icon, $label, $title])
            <button
                type="button"
                title="{{ $title }}"
                x-data="{ busy: false }"
                x-on:click="busy = true; $wire.mountAction('{{ $action }}').finally(() => (busy = false))"
                x-bind:disabled="busy"
            >
                <x-filament::icon :icon="$icon" />
                <span>{{ $label }}</span>
            </button>
        @endforeach
    </div>
</div>
