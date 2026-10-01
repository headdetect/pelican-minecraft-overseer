<?php

// Game rules shown on the Config page. They live in the world save, not a text file,
// so they are read and changed with the gamerule command over RCON and apply instantly.
//
// Minecraft 1.21.11 renamed every rule to snake_case. The key is the new name and
// "old" is the name before that (null when the rule didn't exist yet). A rule only
// shows when the server answers for it, so rules a version doesn't have stay hidden.

return [
    'title' => 'Game rules',
    'file' => null,
    'format' => 'gamerules',
    'icon' => 'tabler-adjustments',

    'groups' => [
        'Players' => [
            'keep_inventory' => ['old' => 'keepInventory', 'title' => 'Keep inventory', 'help' => 'Players keep their items and XP when they die.', 'type' => 'bool'],
            'players_sleeping_percentage' => ['old' => 'playersSleepingPercentage', 'title' => 'Sleep to skip night', 'help' => 'How many players must sleep to skip the night.', 'type' => 'range', 'min' => 0, 'max' => 100, 'unit' => '%'],
            'natural_health_regeneration' => ['old' => 'naturalRegeneration', 'title' => 'Natural healing', 'help' => 'Health refills when players are well fed.', 'type' => 'bool'],
            'immediate_respawn' => ['old' => 'doImmediateRespawn', 'title' => 'Skip death screen', 'help' => 'Players respawn right away when they die.', 'type' => 'bool'],
            'respawn_radius' => ['old' => 'spawnRadius', 'title' => 'Spawn spread', 'help' => 'How far from the spawn point new players can appear.', 'type' => 'int', 'min' => 0, 'max' => 128, 'unit' => 'blocks'],
            'pvp' => ['old' => null, 'title' => 'Player vs player', 'help' => 'Players can hurt each other.', 'type' => 'bool'],
            'locator_bar' => ['old' => 'locatorBar', 'title' => 'Locator bar', 'help' => 'Show nearby players as dots on the XP bar.', 'type' => 'bool'],
            'limited_crafting' => ['old' => 'doLimitedCrafting', 'title' => 'Recipe unlocks only', 'help' => 'Players can only craft recipes they have unlocked.', 'type' => 'bool'],
        ],
        'Damage' => [
            'fall_damage' => ['old' => 'fallDamage', 'title' => 'Fall damage', 'help' => 'Falling hurts.', 'type' => 'bool'],
            'fire_damage' => ['old' => 'fireDamage', 'title' => 'Fire damage', 'help' => 'Fire and lava hurt.', 'type' => 'bool'],
            'drowning_damage' => ['old' => 'drowningDamage', 'title' => 'Drowning', 'help' => 'Running out of air hurts.', 'type' => 'bool'],
            'freeze_damage' => ['old' => 'freezeDamage', 'title' => 'Freezing', 'help' => 'Powder snow hurts.', 'type' => 'bool'],
        ],
        'World' => [
            'advance_time' => ['old' => 'doDaylightCycle', 'title' => 'Daylight cycle', 'help' => 'The sun moves. Off freezes the time of day.', 'type' => 'bool'],
            'advance_weather' => ['old' => 'doWeatherCycle', 'title' => 'Weather changes', 'help' => 'Rain and storms come and go on their own.', 'type' => 'bool'],
            'random_tick_speed' => ['old' => 'randomTickSpeed', 'title' => 'Growth speed', 'help' => 'How fast crops and trees grow. 3 is normal.', 'type' => 'int', 'min' => 0, 'max' => 1000],
            'doFireTick' => ['old' => 'doFireTick', 'new' => null, 'title' => 'Fire spreads', 'help' => 'Fire can spread and burn out on its own.', 'type' => 'bool'],
            'fire_spread_radius_around_player' => ['old' => null, 'title' => 'Fire spread distance', 'help' => 'Fire only spreads this close to a player. 0 stops it spreading.', 'type' => 'int', 'min' => -1, 'max' => 1000, 'unit' => 'blocks'],
            'tnt_explodes' => ['old' => 'tntExplodes', 'title' => 'TNT explodes', 'help' => 'Lit TNT blows up.', 'type' => 'bool'],
            'block_drops' => ['old' => 'doTileDrops', 'title' => 'Blocks drop items', 'help' => 'Broken blocks drop themselves.', 'type' => 'bool'],
            'water_source_conversion' => ['old' => 'waterSourceConversion', 'title' => 'Infinite water', 'help' => 'Two water sources make a new one.', 'type' => 'bool'],
            'lava_source_conversion' => ['old' => 'lavaSourceConversion', 'title' => 'Infinite lava', 'help' => 'Two lava sources make a new one.', 'type' => 'bool'],
            'allow_entering_nether_using_portals' => ['old' => null, 'title' => 'Nether portals', 'help' => 'Players can travel to the Nether.', 'type' => 'bool'],
            'spectators_generate_chunks' => ['old' => 'spectatorsGenerateChunks', 'title' => 'Spectators load new land', 'help' => 'Spectators can make the world generate new chunks.', 'type' => 'bool'],
        ],
        'Mobs' => [
            'mob_griefing' => ['old' => 'mobGriefing', 'title' => 'Mob griefing', 'help' => 'Creepers, endermen and other mobs can change blocks.', 'type' => 'bool'],
            'spawn_mobs' => ['old' => 'doMobSpawning', 'title' => 'Mob spawning', 'help' => 'Mobs spawn on their own.', 'type' => 'bool'],
            'spawn_monsters' => ['old' => null, 'title' => 'Hostile mobs', 'help' => 'Zombies, creepers and friends can spawn.', 'type' => 'bool'],
            'spawn_phantoms' => ['old' => 'doInsomnia', 'title' => 'Phantoms', 'help' => "Phantoms come for players who haven't slept.", 'type' => 'bool'],
            'spawn_patrols' => ['old' => 'doPatrolSpawning', 'title' => 'Pillager patrols', 'help' => 'Pillager patrols wander the world.', 'type' => 'bool'],
            'spawn_wandering_traders' => ['old' => 'doTraderSpawning', 'title' => 'Wandering traders', 'help' => 'Wandering traders visit now and then.', 'type' => 'bool'],
            'spawn_wardens' => ['old' => 'doWardenSpawning', 'title' => 'Wardens', 'help' => 'Wardens can come out of sculk shriekers.', 'type' => 'bool'],
            'mob_drops' => ['old' => 'doMobLoot', 'title' => 'Mob loot', 'help' => 'Mobs drop items and XP when killed.', 'type' => 'bool'],
            'forgive_dead_players' => ['old' => 'forgiveDeadPlayers', 'title' => 'Mobs forgive', 'help' => 'Angry neutral mobs calm down when their target dies.', 'type' => 'bool'],
            'universal_anger' => ['old' => 'universalAnger', 'title' => 'Group anger', 'help' => 'Angry neutral mobs attack any nearby player.', 'type' => 'bool'],
            'max_entity_cramming' => ['old' => 'maxEntityCramming', 'title' => 'Crowding limit', 'help' => 'Mobs packed tighter than this start taking damage.', 'type' => 'int', 'min' => 0, 'max' => 1000],
        ],
        'Chat' => [
            'show_death_messages' => ['old' => 'showDeathMessages', 'title' => 'Death messages', 'help' => 'Show a chat message when someone dies.', 'type' => 'bool'],
            'show_advancement_messages' => ['old' => 'announceAdvancements', 'title' => 'Advancement messages', 'help' => 'Show a chat message when someone earns an advancement.', 'type' => 'bool'],
            'send_command_feedback' => ['old' => 'sendCommandFeedback', 'title' => 'Command replies', 'help' => 'Players see the result of commands they run.', 'type' => 'bool'],
            'command_block_output' => ['old' => 'commandBlockOutput', 'title' => 'Command block chat', 'help' => 'Ops see what command blocks run.', 'type' => 'bool'],
            'log_admin_commands' => ['old' => 'logAdminCommands', 'title' => 'Log admin commands', 'help' => 'Write commands ops run to the server log.', 'type' => 'bool'],
            'reduced_debug_info' => ['old' => 'reducedDebugInfo', 'title' => 'Hide coordinates', 'help' => 'The F3 screen hides coordinates and other details.', 'type' => 'bool'],
        ],
    ],
];
