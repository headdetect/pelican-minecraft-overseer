<?php

namespace Headdetect\Overseer\Support;

/**
 * Checks that a request to the interactions endpoint came from Discord.
 * Discord signs the timestamp plus the raw body with the application's
 * Ed25519 key, and refuses to save an endpoint URL that accepts a bad
 * signature. Pure, so it can be tested without a panel.
 */
final class DiscordSignature
{
    public static function verify(string $publicKeyHex, string $signatureHex, string $timestamp, string $body): bool
    {
        if (!preg_match('/^[0-9a-f]{64}$/i', $publicKeyHex) || !preg_match('/^[0-9a-f]{128}$/i', $signatureHex) || $timestamp === '') {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached(hex2bin($signatureHex), $timestamp . $body, hex2bin($publicKeyHex));
        } catch (\SodiumException) {
            return false;
        }
    }
}
