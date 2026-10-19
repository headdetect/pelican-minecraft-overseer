<?php

namespace Headdetect\Overseer\Support;

/**
 * Turns Modrinth's API answers for a modpack project into what /info and the
 * Overview show. Pure, so it can be tested without the network.
 */
final class ModrinthPack
{
    private const SAFE = '/^[A-Za-z0-9_-]{1,64}$/';

    /** Whether an egg's PROJECT_ID names a Modrinth project. The egg uses "zip" for a pack from a file. */
    public static function isProject(mixed $value): bool
    {
        return is_string($value) && $value !== 'zip' && preg_match(self::SAFE, $value) === 1;
    }

    /**
     * @param  array<string, mixed>  $project  GET /project/{id}
     * @param  array<int, mixed>  $versions  GET /project/{id}/version
     * @param  ?string  $pinned  a version id, such as the egg's VERSION_ID, or null for the newest release
     * @return ?array{name: string, version: ?string, provider: string, url: string, download_url: ?string}
     */
    public static function fromApi(array $project, array $versions, ?string $pinned = null): ?array
    {
        $slug = (string) ($project['slug'] ?? '');
        $title = trim(strip_tags((string) ($project['title'] ?? '')));

        if (($project['project_type'] ?? null) !== 'modpack' || $title === '' || preg_match(self::SAFE, $slug) !== 1) {
            return null;
        }

        $version = self::pick($versions, $pinned);
        $id = (string) ($version['id'] ?? '');
        $number = trim(strip_tags((string) ($version['version_number'] ?? '')));

        return [
            'name' => $title,
            'version' => $number !== '' ? $number : null,
            'provider' => 'modrinth',
            'url' => "https://modrinth.com/modpack/$slug",
            'download_url' => preg_match('/^[A-Za-z0-9]{1,16}$/', $id) === 1 ? "https://modrinth.com/modpack/$slug/version/$id" : null,
        ];
    }

    /**
     * The pinned version if the project has it, otherwise the newest release,
     * otherwise the newest version of any kind.
     *
     * @param  array<int, mixed>  $versions
     * @return ?array<string, mixed>
     */
    private static function pick(array $versions, ?string $pinned): ?array
    {
        $versions = array_values(array_filter($versions, 'is_array'));

        if ($pinned !== null && $pinned !== '') {
            foreach ($versions as $version) {
                if (($version['id'] ?? null) === $pinned) {
                    return $version;
                }
            }
        }

        usort($versions, fn (array $a, array $b) => strcmp((string) ($b['date_published'] ?? ''), (string) ($a['date_published'] ?? '')));

        foreach ($versions as $version) {
            if (($version['version_type'] ?? 'release') === 'release') {
                return $version;
            }
        }

        return $versions[0] ?? null;
    }
}
