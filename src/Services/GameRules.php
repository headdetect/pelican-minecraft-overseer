<?php

namespace Headdetect\Underseer\Services;

use App\Models\Server;

/**
 * Game rules Underseer exposes as switches. Minecraft 1.21.11 renamed every rule
 * to snake_case, so each one carries both names and the right one is picked per server.
 */
class GameRules
{
    /** label => [new name (1.21.11+), old name, description] */
    public const RULES = [
        'keep_inventory' => ['keep_inventory', 'keepInventory', 'Players keep their items and XP when they die.'],
        'mob_griefing' => ['mob_griefing', 'mobGriefing', 'Creepers, endermen and other mobs can change blocks.'],
        'advance_time' => ['advance_time', 'doDaylightCycle', 'The sun moves. Off freezes the time of day.'],
        'advance_weather' => ['advance_weather', 'doWeatherCycle', 'Rain and storms come and go on their own.'],
        'show_death_messages' => ['show_death_messages', 'showDeathMessages', 'Show a chat message when someone dies.'],
        'natural_health_regeneration' => ['natural_health_regeneration', 'naturalRegeneration', 'Health refills when players are well fed.'],
    ];

    public const LABELS = [
        'keep_inventory' => 'Keep inventory',
        'mob_griefing' => 'Mob griefing',
        'advance_time' => 'Daylight cycle',
        'advance_weather' => 'Weather changes',
        'show_death_messages' => 'Death messages',
        'natural_health_regeneration' => 'Natural healing',
    ];

    public function __construct(private readonly ConsoleService $console) {}

    /**
     * Current values, or null for each rule when they can't be read (no RCON).
     *
     * @return array<string, ?bool>
     */
    public function values(Server $server): array
    {
        $values = [];
        $modern = $this->usesModernNames($server);

        foreach (self::RULES as $key => [$new, $old]) {
            $reply = $modern === null ? null : $this->console->query($server, 'gamerule ' . ($modern ? $new : $old));
            $values[$key] = self::parseValue($reply);
        }

        return $values;
    }

    public function set(Server $server, string $key, bool $value): void
    {
        [$new, $old] = self::RULES[$key];
        $state = $value ? 'true' : 'false';

        $modern = $this->usesModernNames($server);

        if ($modern === null) {
            // Without RCON the version is unknown: send both spellings. The one that doesn't exist only prints an error in the console.
            $this->console->run($server, 'gamerule', "gamerule $new $state", $key);
            $this->console->run($server, 'gamerule', "gamerule $old $state", $key);

            return;
        }

        $this->console->run($server, 'gamerule', 'gamerule ' . ($modern ? $new : $old) . " $state", $key);
    }

    /** True for 1.21.11+ names, false for the old camelCase names, null when it can't be checked. */
    public function usesModernNames(Server $server): ?bool
    {
        return cache()->remember("underseer.$server->uuid.gamerule-style", now()->addHour(), function () use ($server) {
            $reply = $this->console->query($server, 'gamerule keep_inventory');

            return $reply === null ? null : self::parseValue($reply) !== null;
        });
    }

    /** Reads "Gamerule keepInventory is currently set to: false". */
    public static function parseValue(?string $reply): ?bool
    {
        if ($reply === null || !preg_match('/set to:\s*(true|false)/i', $reply, $m)) {
            return null;
        }

        return strtolower($m[1]) === 'true';
    }
}
