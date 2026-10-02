<?php

namespace Headdetect\Overseer\Support;

/**
 * Parses the server replies and records that the Overview page shows.
 * Pure functions, so they can be tested without a panel.
 */
final class ServerStats
{
    public const TICKS_PER_DAY = 24000;

    /**
     * The in-game day and clock from "time query day" and, on older versions,
     * "time query daytime".
     *
     * Minecraft 26.1 and newer answer "time query day" with the ticks on the day
     * timeline: "Timeline minecraft:day is at 3153 tick(s)". Older versions answer
     * it with the number of days passed, and "time query daytime" with the ticks
     * into the current day: "The time is 3153".
     *
     * @return ?array{day: int, clock: string, phase: string}
     */
    public static function gameTime(?string $dayReply, ?string $daytimeReply = null): ?array
    {
        if ($dayReply !== null && preg_match('/Timeline minecraft:day is at (-?\d+) tick/', $dayReply, $m)) {
            $total = (int) $m[1];

            return self::clock(intdiv($total, self::TICKS_PER_DAY), $total % self::TICKS_PER_DAY);
        }

        if (
            $dayReply !== null && $daytimeReply !== null
            && preg_match('/The time is (\d+)/', $dayReply, $day)
            && preg_match('/The time is (\d+)/', $daytimeReply, $daytime)
        ) {
            return self::clock((int) $day[1], (int) $daytime[1] % self::TICKS_PER_DAY);
        }

        return null;
    }

    /**
     * Tick 0 of a day is 06:00, and 1000 ticks are one in-game hour.
     * Days are counted from 1, like a calendar.
     *
     * @return array{day: int, clock: string, phase: string}
     */
    public static function clock(int $daysPassed, int $ticks): array
    {
        $ticks = (($ticks % self::TICKS_PER_DAY) + self::TICKS_PER_DAY) % self::TICKS_PER_DAY;
        $minutes = (intdiv($ticks * 60, 1000) + 6 * 60) % (24 * 60);

        return [
            'day' => max(0, $daysPassed) + 1,
            'clock' => sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60),
            'phase' => match (true) {
                $ticks < 12000 => 'day',
                $ticks < 13000 => 'sunset',
                $ticks < 23000 => 'night',
                default => 'sunrise',
            },
        ];
    }

    /**
     * The Minecraft version from the "version" command. Vanilla 1.21.6 and newer
     * reply with lines like "name = 26.1.2". Paper replies with "(MC: 1.21.1)".
     */
    public static function version(?string $reply): ?string
    {
        if ($reply === null) {
            return null;
        }

        if (preg_match('/\(MC: ([0-9][\w.\-]*)\)/', $reply, $m) || preg_match('/name = ([0-9][\w.\-]*?)(?=\s*[a-z_]+ = |\s|$)/', $reply, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * The Minecraft version from the server's startup variables, for when RCON
     * can't answer. Pelican's Minecraft eggs use these names.
     *
     * @param  array<string, mixed>  $environment
     */
    public static function versionFromEnvironment(array $environment): ?string
    {
        foreach (['MC_VERSION', 'MINECRAFT_VERSION', 'VANILLA_VERSION'] as $key) {
            $value = $environment[$key] ?? null;
            if (is_string($value) && preg_match('/^[0-9][\w.\-]*$/', $value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The installed modpack from the Modpack Manager plugin's record, either its
     * modpack_installs row or the .modpack-manager.json file it writes.
     *
     * @param  array<string, mixed>  $record
     * @return ?array{name: string, version: ?string, provider: string, url: ?string}
     */
    public static function modpack(array $record): ?array
    {
        $name = $record['modpack_name'] ?? $record['name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            return null;
        }

        $provider = strtolower((string) ($record['provider'] ?? ''));
        $id = (string) ($record['modpack_id'] ?? '');
        $version = $record['modpack_version'] ?? $record['version'] ?? null;

        return [
            'name' => trim(strip_tags($name)),
            'version' => is_scalar($version) && trim((string) $version) !== '' ? trim(strip_tags((string) $version)) : null,
            'provider' => $provider,
            'url' => self::modpackUrl($provider, $id),
        ];
    }

    /**
     * The installed modpack from modrinth.index.json, which the Modrinth generic
     * egg leaves in the server root. The egg's PROJECT_ID variable is the
     * project, so it gives the link. PROJECT_ID "zip" means a pack from a file.
     *
     * @param  array<string, mixed>  $index
     * @return ?array{name: string, version: ?string, provider: string, url: ?string}
     */
    public static function modpackFromIndex(array $index, ?string $projectId): ?array
    {
        $projectId = $projectId !== null && $projectId !== 'zip' ? $projectId : '';

        return self::modpack([
            'provider' => 'modrinth',
            'modpack_id' => $projectId,
            'name' => $index['name'] ?? null,
            'version' => $index['versionId'] ?? null,
        ]);
    }

    public static function modpackUrl(string $provider, string $id): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
            return null;
        }

        return match ($provider) {
            'modrinth' => "https://modrinth.com/modpack/$id",
            'curseforge' => "https://www.curseforge.com/projects/$id",
            default => null,
        };
    }

    /** Uptime as "3d 4h", "4h 12m" or "12m". */
    public static function uptime(int $milliseconds): string
    {
        $minutes = intdiv(max(0, $milliseconds), 60000);
        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);
        $minutes %= 60;

        return match (true) {
            $days > 0 => "{$days}d {$hours}h",
            $hours > 0 => "{$hours}h {$minutes}m",
            default => "{$minutes}m",
        };
    }
}
