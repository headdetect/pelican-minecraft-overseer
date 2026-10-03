<?php

namespace Headdetect\Overseer\Http\Controllers\Api;

use App\Models\Server;
use Headdetect\Overseer\Services\QuickCommands;
use Headdetect\Overseer\Support\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The Overview's one-click commands, the broadcast and command forms, and chat. */
class CommandController extends ApiController
{
    public function run(Request $request, Server $server, string $name, QuickCommands $commands): JsonResponse
    {
        $permission = QuickCommands::permission($name);
        abort_if($permission === null, 404);
        $this->authorizeAny($server, $permission);

        return $this->attempt(
            trans('overseer::overseer.commands.failed'),
            fn () => $commands->run($server, $name, $request->only(['message', 'command'])),
        );
    }

    public function chat(Request $request, Server $server, QuickCommands $commands): JsonResponse
    {
        $this->authorizeAny($server, Permission::COMMANDS_OPS);
        $data = $request->validate(['message' => ['required', 'string', 'max:200']]);

        return $this->attempt(trans('overseer::overseer.chat.failed'), fn () => $commands->chat($server, $data['message']));
    }
}
