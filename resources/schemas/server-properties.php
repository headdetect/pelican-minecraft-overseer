<?php

// Settings from server.properties shown on the Config page.
//
// One entry per setting. A setting only shows when its key is in the server's file,
// so keys that newer Minecraft versions removed (pvp, allow-nether, ...) simply
// disappear there and turn up under Game rules instead.
//
//   title    plain-words name
//   help     one short sentence
//   type     bool | int | range | enum | string | password
//   min/max  bounds for int and range; unit is shown next to the number
//   options  value => label, for enum
//   default  vanilla value, for the reset button. Left out where a reset would
//            cut Overseer off, such as enable-rcon.
//   restart  true when the server only reads it on start
//   live     console command that applies it right away when the server is running.
//            A string with {value}, or [file value => command].

return [
    'title' => 'Server settings',
    'file' => 'server.properties',
    'format' => 'properties',
    'icon' => 'tabler-server-cog',

    // Never shown or written: Pelican sets the address and port from the allocation,
    // and secrets must not reach the browser.
    'hidden' => [
        'server-ip', 'server-port',
        'management-server-secret', 'management-server-tls-keystore-password',
        'text-filtering-config',
    ],

    'groups' => [
        'World' => [
            'level-name' => ['default' => 'world', 'title' => 'World folder', 'help' => 'Which world folder the server loads.', 'type' => 'string', 'restart' => true],
            'level-seed' => ['default' => '', 'title' => 'World seed', 'help' => 'Only used when a new world is made. Empty means random.', 'type' => 'string', 'restart' => true, 'placeholder' => 'Random'],
            'level-type' => ['default' => 'minecraft:normal', 'title' => 'World type', 'help' => 'The shape of new worlds. Changing it does not touch the current world.', 'type' => 'enum', 'restart' => true, 'options' => [
                'minecraft:normal' => 'Normal',
                'minecraft:flat' => 'Superflat',
                'minecraft:large_biomes' => 'Large biomes',
                'minecraft:amplified' => 'Amplified',
                'minecraft:single_biome_surface' => 'Single biome',
            ]],
            'generate-structures' => ['default' => true, 'title' => 'Villages and temples', 'help' => 'New chunks can have villages, temples and other structures.', 'type' => 'bool', 'restart' => true],
            'allow-nether' => ['default' => true, 'title' => 'Nether', 'help' => 'Players can travel to the Nether.', 'type' => 'bool', 'restart' => true],
            'spawn-protection' => ['default' => 16, 'title' => 'Spawn protection', 'help' => "Non-ops can't build this close to spawn. 0 turns it off.", 'type' => 'int', 'min' => 0, 'max' => 1000, 'unit' => 'blocks', 'restart' => true],
            'max-world-size' => ['default' => 29999984, 'title' => 'World border size', 'help' => 'The farthest the world border can ever go from the center.', 'type' => 'int', 'min' => 1, 'max' => 29999984, 'unit' => 'blocks', 'restart' => true],
        ],
        'Players' => [
            'max-players' => ['default' => 20, 'title' => 'Max players', 'help' => 'How many people can be online at once.', 'type' => 'int', 'min' => 1, 'max' => 1000, 'unit' => 'players', 'restart' => true],
            'white-list' => ['default' => false, 'title' => 'Whitelist', 'help' => 'Only players on the whitelist can join.', 'type' => 'bool', 'live' => ['true' => 'whitelist on', 'false' => 'whitelist off']],
            'enforce-whitelist' => ['default' => false, 'title' => 'Kick when removed', 'help' => 'Kick online players who are not on the whitelist when it changes.', 'type' => 'bool', 'restart' => true],
            'online-mode' => ['default' => true, 'title' => 'Check accounts', 'help' => 'Only real Minecraft accounts can join. Keep this on unless you run a proxy.', 'type' => 'bool', 'restart' => true],
            'player-idle-timeout' => ['default' => 0, 'title' => 'AFK kick', 'help' => 'Kick players who are idle this long. 0 means never.', 'type' => 'int', 'min' => 0, 'max' => 1440, 'unit' => 'minutes', 'live' => 'setidletimeout {value}'],
            'op-permission-level' => ['default' => '4', 'title' => 'Operator power', 'help' => 'What new operators are allowed to do.', 'type' => 'enum', 'restart' => true, 'options' => [
                '1' => '1: Bypass spawn protection',
                '2' => '2: Cheat commands',
                '3' => '3: Kick and ban',
                '4' => '4: Everything, even stop the server',
            ]],
            'hide-online-players' => ['default' => false, 'title' => 'Hide player list', 'help' => "The server list doesn't show who is online.", 'type' => 'bool', 'restart' => true],
            'log-ips' => ['default' => true, 'title' => 'Log IP addresses', 'help' => "Write players' IP addresses to the server log.", 'type' => 'bool', 'restart' => true],
        ],
        'Gameplay' => [
            'gamemode' => ['default' => 'survival', 'title' => 'Default game mode', 'help' => 'The mode new players start in.', 'type' => 'enum', 'live' => 'defaultgamemode {value}', 'options' => [
                'survival' => 'Survival',
                'creative' => 'Creative',
                'adventure' => 'Adventure',
                'spectator' => 'Spectator',
            ]],
            'force-gamemode' => ['default' => false, 'title' => 'Force game mode', 'help' => 'Put everyone back in the default mode each time they join.', 'type' => 'bool', 'restart' => true],
            'difficulty' => ['default' => 'easy', 'title' => 'Difficulty', 'help' => 'How tough mobs are, and whether hunger can kill.', 'type' => 'enum', 'live' => 'difficulty {value}', 'options' => [
                'peaceful' => 'Peaceful',
                'easy' => 'Easy',
                'normal' => 'Normal',
                'hard' => 'Hard',
            ]],
            'hardcore' => ['default' => false, 'title' => 'Hardcore', 'help' => 'One life. Players who die become spectators.', 'type' => 'bool', 'restart' => true],
            'pvp' => ['default' => true, 'title' => 'Player vs player', 'help' => 'Players can hurt each other.', 'type' => 'bool', 'restart' => true],
            'spawn-monsters' => ['default' => true, 'title' => 'Hostile mobs', 'help' => 'Zombies, creepers and friends can spawn.', 'type' => 'bool', 'restart' => true],
            'spawn-animals' => ['default' => true, 'title' => 'Animals', 'help' => 'Cows, pigs and other animals can spawn.', 'type' => 'bool', 'restart' => true],
            'spawn-npcs' => ['default' => true, 'title' => 'Villagers', 'help' => 'Villagers can spawn.', 'type' => 'bool', 'restart' => true],
            'allow-flight' => ['default' => false, 'title' => 'Allow flying', 'help' => "Don't kick players the server thinks are flying. Needed for some mods.", 'type' => 'bool', 'restart' => true],
            'enable-command-block' => ['default' => false, 'title' => 'Command blocks', 'help' => 'Command blocks can run commands.', 'type' => 'bool', 'restart' => true],
        ],
        'Server list' => [
            'motd' => ['default' => 'A Minecraft Server', 'title' => 'Message of the day', 'help' => 'The line under the server name in the multiplayer list.', 'type' => 'string', 'max' => 200, 'restart' => true],
            'enable-status' => ['default' => true, 'title' => 'Show as online', 'help' => 'The server shows up as online in the multiplayer list.', 'type' => 'bool', 'restart' => true],
            'enforce-secure-profile' => ['default' => true, 'title' => 'Require signed chat', 'help' => 'Players must have chat signing turned on to join.', 'type' => 'bool', 'restart' => true],
            'resource-pack' => ['default' => '', 'title' => 'Resource pack link', 'help' => 'A download link players get when they join. Empty means none.', 'type' => 'string', 'restart' => true, 'placeholder' => 'None'],
            'require-resource-pack' => ['default' => false, 'title' => 'Require resource pack', 'help' => 'Kick players who say no to the resource pack.', 'type' => 'bool', 'restart' => true],
        ],
        'Remote access' => [
            'enable-rcon' => ['title' => 'Remote console (RCON)', 'help' => 'Lets Overseer see who is online and read game rules.', 'type' => 'bool', 'restart' => true],
            'rcon.port' => ['default' => 25575, 'title' => 'RCON port', 'help' => 'The panel must reach this port, but the internet must not. Bind it to a private IP, never 0.0.0.0.', 'type' => 'int', 'min' => 1, 'max' => 65535, 'restart' => true],
            'rcon.password' => ['title' => 'RCON password', 'help' => 'Leave empty to keep the current one. Use something long and random.', 'type' => 'password', 'restart' => true],
            'broadcast-rcon-to-ops' => ['default' => true, 'title' => 'Show RCON to ops', 'help' => 'Ops in game see every command Overseer runs.', 'type' => 'bool', 'restart' => true],
            'enable-query' => ['default' => false, 'title' => 'Query', 'help' => 'Answer status requests from server list sites and tools.', 'type' => 'bool', 'restart' => true],
            'query.port' => ['default' => 25565, 'title' => 'Query port', 'help' => 'Must be one of this server\'s allocations.', 'type' => 'int', 'min' => 1, 'max' => 65535, 'restart' => true],
        ],
        'Performance' => [
            'view-distance' => ['default' => 10, 'title' => 'View distance', 'help' => 'How far players can see. Higher uses more memory.', 'type' => 'range', 'min' => 3, 'max' => 32, 'unit' => 'chunks', 'restart' => true],
            'simulation-distance' => ['default' => 10, 'title' => 'Simulation distance', 'help' => 'How far from players crops grow and mobs move.', 'type' => 'range', 'min' => 3, 'max' => 32, 'unit' => 'chunks', 'restart' => true],
            'entity-broadcast-range-percentage' => ['default' => 100, 'title' => 'Entity view range', 'help' => 'How far away players see mobs and items, compared to normal.', 'type' => 'int', 'min' => 10, 'max' => 1000, 'unit' => '%', 'restart' => true],
            'pause-when-empty-seconds' => ['default' => 60, 'title' => 'Pause when empty', 'help' => 'Stop ticking the world after nobody has been online this long. 0 means never.', 'type' => 'int', 'min' => 0, 'max' => 86400, 'unit' => 'seconds', 'restart' => true],
            'max-tick-time' => ['default' => 60000, 'title' => 'Freeze watchdog', 'help' => 'Stop the server if one tick takes longer than this. -1 turns it off.', 'type' => 'int', 'min' => -1, 'max' => 600000, 'unit' => 'ms', 'restart' => true],
            'sync-chunk-writes' => ['default' => true, 'title' => 'Safe chunk saving', 'help' => 'Save chunks one at a time. Safer, a little slower.', 'type' => 'bool', 'restart' => true],
        ],
    ],
];
