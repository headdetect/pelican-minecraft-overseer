<?php

namespace Headdetect\Overseer\Services;

use Headdetect\Overseer\Support\ModrinthPack;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Asks Modrinth for a modpack project and its versions, so the Overview and
 * /info show the pack's current version without anyone editing a setting.
 * The answer is cached for ten minutes. When Modrinth is down, the last good
 * answer stands in for a day.
 *
 * A project that isn't public yet (private, a draft, or in review) answers
 * 404 to everyone but its team. The egg's MODRINTH_TOKEN, when it is set, goes
 * in the Authorization header so those projects show. The token is sent only
 * to api.modrinth.com. It isn't cached or logged, and the cache key doesn't
 * include it.
 */
class ModrinthService
{
    private const BASE = 'https://api.modrinth.com/v2';

    /** Modrinth asks every client to send a User-Agent that says who it is. */
    private const USER_AGENT = 'headdetect/pelican-minecraft-overseer (https://github.com/headdetect/pelican-minecraft-overseer)';

    /**
     * @param  string  $project  a Modrinth project id or slug, already checked with ModrinthPack::isProject
     * @param  ?string  $versionId  a pinned version number or id, or null for the newest release
     * @param  ?string  $token  a Modrinth personal access token, or null to ask without a login
     * @return ?array{name: string, version: ?string, provider: string, url: string, download_url: ?string}
     */
    public function pack(string $project, ?string $versionId, ?string $token = null): ?array
    {
        $key = "overseer:modrinth:$project:" . ($versionId ?: 'latest');

        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached ?: Cache::get("$key:last");
        }

        $pack = null;

        try {
            $pack = ModrinthPack::fromApi(
                (array) $this->get("/project/$project", [], $token)->json(),
                (array) $this->get("/project/$project/version", ['include_changelog' => 'false'], $token)->json(),
                $versionId,
            );
        } catch (RequestException $exception) {
            // A project that doesn't exist is a wrong id, not an outage.
            if ($exception->response->status() !== 404) {
                report($exception);
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        if ($pack !== null) {
            Cache::put($key, $pack, now()->addMinutes(10));
            Cache::put("$key:last", $pack, now()->addDay());

            return $pack;
        }

        // Remember the failure for a minute, so a down Modrinth isn't asked on every poll.
        Cache::put($key, false, now()->addMinute());

        return Cache::get("$key:last");
    }

    /**
     * @param  array<string, string>  $query
     *
     * @throws RequestException
     */
    private function get(string $path, array $query, ?string $token): Response
    {
        return $this->client($token)->get($path, $query)->throw();
    }

    private function client(?string $token): PendingRequest
    {
        $client = Http::baseUrl(self::BASE)
            ->withUserAgent(self::USER_AGENT)
            ->timeout(3)
            ->acceptJson();

        // Modrinth takes the token as is, with no "Bearer" in front.
        return $token !== null && $token !== '' ? $client->withHeaders(['Authorization' => $token]) : $client;
    }
}
