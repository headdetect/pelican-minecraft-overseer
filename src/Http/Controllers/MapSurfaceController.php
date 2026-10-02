<?php

namespace Headdetect\Overseer\Http\Controllers;

use App\Enums\ContainerStatus;
use App\Models\Server;
use Exception;
use Headdetect\Overseer\Services\Map\Surface;
use Headdetect\Overseer\Support\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The ground height at a point clicked on the map. A plain route, so the
 * browser can ask while a Livewire poll for the same page is still running.
 */
class MapSurfaceController extends Controller
{
    public function __invoke(Request $request, Server $server, Surface $surface): JsonResponse
    {
        abort_unless(Permission::allows(Permission::PLAYERS_CHEAT, $server), 403);

        $data = $request->validate([
            'world' => ['required', 'string', 'max:64'],
            'x' => ['required', 'integer', 'between:-29999984,29999984'],
            'z' => ['required', 'integer', 'between:-29999984,29999984'],
        ]);

        if ($server->retrieveStatus() !== ContainerStatus::Running) {
            return response()->json(['y' => null]);
        }

        try {
            $y = $surface->y($server, Surface::dimension($data['world']), (int) $data['x'], (int) $data['z']);
        } catch (Exception $exception) {
            report($exception);
            $y = null;
        }

        return response()->json(['y' => $y]);
    }
}
