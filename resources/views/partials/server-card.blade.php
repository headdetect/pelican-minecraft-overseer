{{--
    The Server card in the Overview's top row: one row of icon buttons, so the
    card is as tall as the resource cards. Each button mounts the page action
    with the same name, which runs it or opens its form.
--}}
@php
    $buttons = array_filter([
        $this->canRunWorldCommands() ? ['save', 'tabler-device-floppy', trans('overseer::overseer.commands.buttons.save')] : null,
        $this->canRunOpsCommands() ? ['whitelistOn', 'tabler-shield-lock', trans('overseer::overseer.commands.buttons.whitelist_on')] : null,
        $this->canRunOpsCommands() ? ['whitelistOff', 'tabler-shield-off', trans('overseer::overseer.commands.buttons.whitelist_off')] : null,
        $this->canRunOpsCommands() ? ['broadcast', 'tabler-speakerphone', trans('overseer::overseer.commands.buttons.broadcast')] : null,
        $this->canRunOpsCommands() ? ['custom', 'tabler-terminal-2', trans('overseer::overseer.commands.buttons.custom')] : null,
    ]);
@endphp

<div class="us-stat">
    <div class="us-stat-label">{{ trans('overseer::overseer.commands.server.title') }}</div>
    <div class="us-btn-group" role="group" aria-label="{{ trans('overseer::overseer.commands.server.title') }}">
        @foreach ($buttons as [$action, $icon, $label])
            <button
                type="button"
                title="{{ $label }}"
                aria-label="{{ $label }}"
                wire:click="mountAction('{{ $action }}')"
                wire:loading.attr="disabled"
                wire:target="mountAction('{{ $action }}')"
            >
                <x-filament::icon :icon="$icon" />
            </button>
        @endforeach
    </div>
</div>
