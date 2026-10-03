<?php

namespace Headdetect\Overseer\Http\Controllers\Api;

use App\Enums\SubuserPermission;
use App\Facades\Activity;
use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use Headdetect\Overseer\Services\ConfigEditor;
use Headdetect\Overseer\Services\ConfigFiles;
use Headdetect\Overseer\Support\ConfigSchema;
use Headdetect\Overseer\Support\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** The Config page: settings from each source, saving them, and the restart that applies them. */
class ConfigController extends ApiController
{
    public function show(Server $server, ConfigEditor $editor): JsonResponse
    {
        $this->authorizeAny($server, Permission::CONFIG_VIEW, Permission::CONFIG_EDIT);

        return response()->json([
            'sources' => $editor->sources($server),
            'restart_needed' => (bool) cache()->get("overseer:restart-needed:$server->uuid", false),
        ]);
    }

    /**
     * Saves the submitted values (source => key => value). With restart=true,
     * restarts the server once everything saved.
     */
    public function save(Request $request, Server $server, ConfigEditor $editor): JsonResponse
    {
        $this->authorizeAny($server, Permission::CONFIG_EDIT);
        $data = $request->validate(['values' => ['required', 'array'], 'restart' => ['boolean']]);
        $restart = $request->boolean('restart');
        abort_if($restart && !$this->canRestart($server), 403);

        try {
            $result = $editor->save($server, $data['values']);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => trans('overseer::overseer.config.invalid'), 'body' => $exception->getMessage()], 422);
        }

        if ($result['restart']) {
            cache()->put("overseer:restart-needed:$server->uuid", true, now()->addDay());
        }

        $notifications = [];
        foreach ($result['failed'] as $source => $message) {
            $notifications[] = ['status' => 'danger', 'title' => trans('overseer::overseer.config.failed', ['source' => $source]), 'body' => $message];
        }

        if ($result['saved'] > 0) {
            $notifications[] = [
                'status' => 'success',
                'title' => trans_choice('overseer::overseer.config.saved', $result['saved'], ['count' => $result['saved']]),
                'body' => implode(' ', array_filter([
                    array_intersect($result['sources'], ['server', 'paper']) ? trans('overseer::overseer.config.saved_backup', ['dir' => ConfigFiles::BACKUP_DIR]) : null,
                    $result['restart'] && !$restart ? trans('overseer::overseer.config.saved_restart') : null,
                ])) ?: null,
            ];
        }

        if ($restart && $result['saved'] > 0 && $result['failed'] === []) {
            $notifications[] = $this->restartServer($server);
        }

        return response()->json([
            'saved' => $result['saved'],
            'failed' => $result['failed'] !== [],
            'notifications' => $notifications,
        ]);
    }

    public function restart(Server $server): JsonResponse
    {
        $this->authorizeAny($server, Permission::CONFIG_VIEW, Permission::CONFIG_EDIT);
        abort_unless($this->canRestart($server), 403);

        $notification = $this->restartServer($server);

        return response()->json($notification, $notification['status'] === 'success' ? 200 : 422);
    }

    /** Config files of mods and plugins, for the Files tab. Pelican's file editor checks file permissions itself. */
    public function files(Server $server, ConfigFiles $files): JsonResponse
    {
        $this->authorizeAny($server, Permission::CONFIG_EDIT);

        return response()->json(['files' => array_values($files->editableFiles($server))]);
    }

    private function canRestart(Server $server): bool
    {
        return (bool) user()?->can(SubuserPermission::ControlRestart, $server);
    }

    /** @return array{status: string, title: string, body?: string} */
    private function restartServer(Server $server): array
    {
        try {
            app(DaemonServerRepository::class)->setServer($server)->power('restart');
            Activity::event('server:power.restart')->log();
            cache()->forget("overseer:restart-needed:$server->uuid");

            return ['status' => 'success', 'title' => trans('overseer::overseer.config.restarting')];
        } catch (\Exception $exception) {
            report($exception);

            return ['status' => 'danger', 'title' => trans('overseer::overseer.config.restart_failed'), 'message' => trans('overseer::overseer.config.restart_failed'), 'body' => $exception->getMessage()];
        }
    }
}
