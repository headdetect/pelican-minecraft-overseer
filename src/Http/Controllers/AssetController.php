<?php

namespace Headdetect\Overseer\Http\Controllers;

use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the built React app from resources/dist/. The page links each file
 * with ?v=<version>, so browsers can keep it for a year.
 */
class AssetController extends Controller
{
    private const TYPES = [
        'overseer.js' => 'text/javascript; charset=utf-8',
        'overseer.css' => 'text/css; charset=utf-8',
    ];

    public function __invoke(string $file): BinaryFileResponse
    {
        abort_unless(isset(self::TYPES[$file]), 404);
        $path = plugin_path('overseer', "resources/dist/$file");
        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => self::TYPES[$file],
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
