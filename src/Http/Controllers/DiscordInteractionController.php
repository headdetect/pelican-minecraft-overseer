<?php

namespace Headdetect\Overseer\Http\Controllers;

use Headdetect\Overseer\Services\Discord\InteractionHandler;
use Headdetect\Overseer\Support\DiscordSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Discord sends every slash command here as a signed POST. There's no
 * session or panel user: the signature is the only check that the request
 * came from Discord, and the guild id picks the server.
 */
class DiscordInteractionController
{
    private const PING = 1;

    private const COMMAND = 2;

    public function __invoke(Request $request): JsonResponse
    {
        $valid = DiscordSignature::verify(
            (string) config('overseer.discord.public_key'),
            (string) $request->header('X-Signature-Ed25519'),
            (string) $request->header('X-Signature-Timestamp'),
            $request->getContent(),
        );

        if (!$valid) {
            return response()->json(['message' => 'Invalid request signature.'], 401);
        }

        $interaction = json_decode($request->getContent(), true);

        return match (is_array($interaction) ? ($interaction['type'] ?? null) : null) {
            self::PING => response()->json(['type' => self::PING]),
            self::COMMAND => response()->json(app(InteractionHandler::class)->handle($interaction)),
            default => response()->json(['message' => 'Unsupported interaction.'], 400),
        };
    }
}
