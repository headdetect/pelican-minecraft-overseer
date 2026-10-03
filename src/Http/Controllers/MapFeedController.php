<?php

namespace Headdetect\Overseer\Http\Controllers;

use App\Models\Server;
use Headdetect\Overseer\Services\MapFeed;
use Headdetect\Overseer\Support\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * The Overview's poll. A plain route instead of a Livewire call, because
 * Livewire runs one request at a time per page: a slow poll on a busy server
 * would hold up every button click behind it.
 */
class MapFeedController extends Controller
{
    public function __invoke(Server $server, MapFeed $feed): JsonResponse
    {
        abort_unless(Permission::allows(Permission::MAP_VIEW, $server), 403);

        return response()->json($feed->payload($server));
    }
}
