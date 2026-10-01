# Local development

This folder runs a Pelican panel, wings and a Fabric Minecraft server on your
machine. The panel loads Underseer straight from this checkout. After you
change a PHP or Blade file, reload the page to see the change.

## Requirements

- Docker Engine on Linux, with the compose plugin. This setup was tested only
  there. Wings creates the game container on the same Docker daemon.
- About 5 GB of free memory while the stack runs.
- These ports free on localhost: 8890 (panel), 8891 (wings), 2023 (SFTP),
  25565 (game), 25575 (RCON) and 8100 (squaremap).

## Start and stop

```bash
dev/up.sh            # start, and seed on the first run
dev/down.sh          # stop everything, keep the world and the database
dev/down.sh --wipe   # stop everything and delete all dev data
```

The first `dev/up.sh` takes a few minutes. It does what the web installer and
the admin pages do by hand:

1. Migrates the panel database and creates the admin account.
2. Installs Underseer.
3. Creates a node and writes the wings configuration.
4. Imports the Fabric egg and creates a server called `dev`.
5. Writes `server.properties` with RCON on, accepts the EULA and downloads
   Fabric API and squaremap from Modrinth.
6. Starts the server.

When it finishes, it prints the panel address and the login:

- Panel: `http://localhost:8890`
- Login: `admin@underseer.test`, password `underseer`
- Game: `localhost:25565`

`dev/up.sh` picks the newest Minecraft release that has both a squaremap and a
Fabric API build. To pin a version, set `MC_VERSION` before the first run:

```bash
MC_VERSION=1.21.8 dev/up.sh
```

## Changing the plugin

The panel mounts `src`, `resources`, `routes`, `config`, `database`, `lang` and
`tests` from this checkout, read-only. Reload the page to see a change.

Some changes need one more step:

- If you add or remove a page, run `dev/artisan filament:cache-components`.
- If you add a migration, run `dev/artisan migrate`.
- If you change `plugin.json`, run `dev/up.sh` again. The panel uses a copy of
  that file, because it stores the install state of the plugin in it.

The panel runs with `APP_DEBUG` on, so an error page shows the stack trace.
Plugin dev mode is also on. An error in Underseer then stops the page, and the
panel does not quietly mark the plugin as errored.

## Tools

- `dev/test` runs `tests/run.php` with the PHP in the panel.
- `dev/artisan <args>` runs `php artisan` in the panel.
- `dev/rcon <command>` sends one RCON command through the RCON client of
  Underseer.
- `dev/shot <page>` saves a screenshot of a panel page.

`dev/shot` logs in as the admin with headless Chromium and saves a PNG in
`dev/.data/shots/`. It also prints the HTTP status, browser console errors and
new lines from the Laravel log. The page is a panel path, or the name of an
Underseer page on the dev server:

```bash
dev/shot players
dev/shot config --click "Game rules"
dev/shot /admin/plugins
```

To see players on the Players page and the map, join `localhost:25565` with
your Minecraft client.
