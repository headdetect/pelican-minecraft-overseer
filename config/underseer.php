<?php

return [
    // RCON is used to read live data (who is online, where they are, game rule values).
    // Connection details are read from the server's own server.properties; these only override them.
    'rcon' => [
        // Host the panel connects to. Empty = the server's allocation IP, or the node's FQDN when the allocation binds 0.0.0.0.
        'host' => env('UNDERSEER_RCON_HOST'),
        'timeout' => (float) env('UNDERSEER_RCON_TIMEOUT', 2.0),
    ],

    // How many entries the "Recent actions" list shows.
    'recent_actions' => (int) env('UNDERSEER_RECENT_ACTIONS', 10),

    // Default reasons offered when kicking or banning.
    'reasons' => ['Griefing', 'Harassment', 'Cheating', 'Spam'],
];
