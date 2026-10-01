<?php

namespace Headdetect\Overseer\Http\Controllers;

use App\Models\Server;
use Headdetect\Overseer\Services\Map\MapService;
use Headdetect\Overseer\Support\Permission;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Passes squaremap tiles through the panel, so the browser only ever talks to the panel.
 */
class MapTileController extends Controller
{
    public function __invoke(Server $server, string $path, MapService $map): Response
    {
        abort_unless(Permission::allows(Permission::MAP_VIEW, $server), 403);

        $response = $map->fetch($server, $path);
        abort_unless($response?->successful(), 404);

        $json = str_ends_with($path, '.json');

        return response($response->body(), 200, [
            'Content-Type' => $json ? 'application/json' : 'image/png',
            // squaremap re-renders tiles as the world changes; a short cache keeps panning cheap.
            'Cache-Control' => 'private, max-age=' . ($json ? 0 : 30),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
