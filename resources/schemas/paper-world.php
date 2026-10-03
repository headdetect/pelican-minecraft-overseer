<?php

// Paper's world settings, shown on the Config page when the server runs Paper.
// Keys are dotted YAML paths. A setting only shows when the file has a plain value
// at that path. Paper reads this file on start.

return [
    'title' => 'Paper settings',
    'file' => 'config/paper-world-defaults.yml',
    'format' => 'yaml',
    'icon' => 'tabler-file-settings',

    'groups' => [
        'Anti-cheat' => [
            'anticheat.anti-xray.enabled' => ['default' => false, 'title' => 'Anti x-ray', 'help' => 'Hide ores from players using x-ray mods.', 'type' => 'bool', 'restart' => true],
        ],
        'Gameplay' => [
            'environment.treasure-maps.enabled' => ['default' => true, 'title' => 'Treasure maps', 'help' => 'Cartographers and chests can give treasure maps.', 'type' => 'bool', 'restart' => true],
            'lootables.auto-replenish' => ['default' => false, 'title' => 'Refill loot chests', 'help' => 'Dungeon and temple chests fill up again over time.', 'type' => 'bool', 'restart' => true],
            'entities.spawning.per-player-mob-spawns' => ['default' => true, 'title' => 'Fair mob spawning', 'help' => 'Each player gets their own share of mobs, so one farm cannot use them all up.', 'type' => 'bool', 'restart' => true],
        ],
        'Performance' => [
            'entities.spawning.despawn-ranges.monster.hard' => ['default' => 128, 'title' => 'Mob despawn distance', 'help' => 'Monsters farther than this from every player disappear.', 'type' => 'int', 'min' => 1, 'max' => 512, 'unit' => 'blocks', 'restart' => true],
            'chunks.max-auto-save-chunks-per-tick' => ['default' => 24, 'title' => 'Chunks saved per tick', 'help' => 'Lower spreads saving out and reduces lag spikes.', 'type' => 'int', 'min' => 1, 'max' => 1000, 'unit' => 'chunks', 'restart' => true],
            'collisions.max-entity-collisions' => ['default' => 8, 'title' => 'Mob push limit', 'help' => 'How many mobs one mob can push at once. Lower helps crowded farms.', 'type' => 'int', 'min' => 0, 'max' => 100, 'restart' => true],
            'environment.optimize-explosions' => ['default' => false, 'title' => 'Faster explosions', 'help' => 'Work out explosions faster. Results can differ a little from vanilla.', 'type' => 'bool', 'restart' => true],
            'hopper.disable-move-event' => ['default' => false, 'title' => 'Faster hoppers', 'help' => "Skip a plugin event for hopper moves. Breaks plugins that protect chests from hoppers.", 'type' => 'bool', 'restart' => true],
            'tick-rates.mob-spawner' => ['default' => 1, 'title' => 'Spawner speed', 'help' => 'Spawners work every this many ticks. 1 is normal, -1 turns them off.', 'type' => 'int', 'min' => -1, 'max' => 100, 'unit' => 'ticks', 'restart' => true],
        ],
    ],
];
