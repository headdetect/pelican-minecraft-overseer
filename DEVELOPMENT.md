# Local development

The dev stack runs a Pelican panel, wings and a Fabric Minecraft server on your
machine. The panel loads Overseer straight from this checkout. After you
change a PHP or Blade file, reload the page to see the change.

## Requirements

- Docker Engine on Linux, with the compose plugin. This setup was tested only
  there. Wings creates the game container on the same Docker daemon.
- About 5 GB of free memory while the stack runs.
- These ports free on localhost: 8890 (panel), 8891 (wings), 2023 (SFTP),
  25565 (game), 25575 (RCON) and 8100 (squaremap).

## Start and stop

```bash
script/bootstrap            # check the tools you need and pull the images
script/bootstrap --update   # pull every image again
script/up                   # bootstrap, start, and seed on the first run
script/down                 # stop everything, keep the world and the database
script/down --wipe          # stop everything and delete all dev data
```

`script/bootstrap` checks for Docker, the Compose plugin and curl. It pulls only
the images you do not have yet. If the stack is not running, it also checks that
the ports above are free.

The first `script/up` takes a few minutes. It does what the web installer and
the admin pages do by hand:

1. Migrates the panel database and creates the admin account.
2. Installs Overseer.
3. Creates a node and writes the wings configuration.
4. Imports the Fabric egg and creates a server called `dev`.
5. Writes `server.properties` with RCON on, accepts the EULA and downloads
   Fabric API, squaremap and Chunky from Modrinth. Chunky is optional: if it
   has no build for the Minecraft version, the step leaves it out.
6. Starts the server.
7. Waits for the server to boot. Then it generates the 16 by 16 chunks around
   spawn and has squaremap render them, so the Live Map shows terrain before
   anyone joins. This happens once per world.

When it finishes, it prints the panel address and the login:

- Panel: `http://localhost:8890`
- Login: `admin@overseer.test`, password `overseer`
- Game: `localhost:25565`

`script/up` picks the newest Minecraft release that has both a squaremap and a
Fabric API build. To pin a version, set `MC_VERSION` before the first run:

```bash
MC_VERSION=1.21.8 script/up
```

## Changing the plugin

The panel mounts `src`, `resources`, `routes`, `config`, `database`, `lang` and
`tests` from this checkout, read-only. Reload the page to see a change.

Some changes need one more step:

- If you add or remove a page, run `script/artisan filament:cache-components`.
- If you add a migration, run `script/artisan migrate`.
- If you change `plugin.json`, run `script/up` again. The panel uses a copy of
  that file, because it stores the install state of the plugin in it.

## Changing the frontend

The pages are a React app in `frontend/`. The panel loads the built files from
`resources/dist/`. Build after each change, then reload the page:

```bash
cd frontend
npm ci              # once
npm run build       # type check, then build into resources/dist/
npm run watch       # build again each time you save a file
npm test            # unit tests for the config checks
```

Commit `resources/dist/` with the source change. CI builds the app again.
If the result differs from what you committed, the check fails.

The panel runs with `APP_DEBUG` on, so an error page shows the stack trace.
Plugin dev mode is also on. An error in Overseer then stops the page, and the
panel does not quietly mark the plugin as errored.

## Tools

- `script/test` runs `tests/run.php` with the PHP in the panel.
- `script/artisan <args>` runs `php artisan` in the panel.
- `script/rcon <command>` sends one RCON command through the RCON client of
  Overseer.
- `script/screenshot <page>` saves a screenshot of a panel page.
- `script/discord-sign <command>` sends a signed Discord interaction to the
  panel, since Discord can't reach localhost. See below.

`script/screenshot` logs in as the admin with headless Chromium and saves a PNG in
`.data/shots/`. It also prints the HTTP status, browser console errors and
new lines from the Laravel log. The page is a panel path, or the name of an
Overseer page on the dev server:

```bash
script/screenshot players
script/screenshot config --click "Game rules"
script/screenshot /admin/plugins
```

`--click TEXT` clicks the first visible element with exactly that text before the
screenshot, and the PNG name gets the clicked text added. `--wait MS` sets the pause
before the screenshot, 1500 milliseconds by default.

To see players on the Players page and the map, join `localhost:25565` with
your Minecraft client.

## Testing the Discord commands

Discord can't reach `localhost`, so `script/discord-sign` stands in for it. It
signs each request with a fixed dev key pair and sends it to the panel.

1. Run `script/discord-sign key` and copy the public key it prints.
2. Open `/admin/plugins`, then Overseer, then Settings. Paste the key as the
   public key, and enter any 18 digits as the application ID and any text as
   the token. The Discord tab then shows its form, though the guild list
   shows an error, since the token isn't real.
3. Run `script/discord-sign link` to link the dev server to a made-up guild.

Then send commands:

```bash
script/discord-sign ping             # 200 {"type":1}
script/discord-sign info
script/discord-sign status
script/discord-sign whitelist Doobie
script/discord-sign whitelist "Cool Gamer"
```

The panel answers each command with `{"type":5}`, which is Discord's
"thinking" reply. With the token `local` the script uses, the panel then writes
the real reply to the Laravel log as `Discord reply`, instead of sending it to
Discord:

```bash
docker exec overseer-dev-panel sh -c 'tail -n 5 storage/logs/*.log'
```
