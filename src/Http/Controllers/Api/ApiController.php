<?php

namespace Headdetect\Overseer\Http\Controllers\Api;

use App\Models\Server;
use Exception;
use Headdetect\Overseer\Support\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Shared checks for the React app's API. Each route needs one of Overseer's
 * permissions, the same one the old Livewire action checked, and a server
 * that isn't suspended, installing or transferring.
 */
abstract class ApiController extends Controller
{
    /** Stops with 403 unless the user has any of the permissions. */
    protected function authorizeAny(Server $server, string ...$permissions): void
    {
        abort_if($server->isInConflictState(), 409, 'The server is busy. Try again once it is ready.');

        foreach ($permissions as $permission) {
            if (Permission::allows($permission, $server)) {
                return;
            }
        }

        abort(403);
    }

    /**
     * Runs an action and turns a failure into a 422 response, with a title and
     * the reason, which the app shows as a notification.
     */
    protected function attempt(string $failedTitle, callable $action): JsonResponse
    {
        try {
            $result = $action();

            return $result instanceof JsonResponse ? $result : response()->json($result ?? ['ok' => true]);
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Exception $exception) {
            return response()->json(['message' => $failedTitle, 'body' => $exception->getMessage()], 422);
        }
    }
}
