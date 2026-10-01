# Overseer

A [Pelican](https://pelican.dev) panel plugin for running a Minecraft Java server without memorising console commands.

**Working now**

- **Live Map**: the world map from squaremap with a marker for everyone online, updated every 5 seconds. Switch between Overworld, Nether and End, drag and zoom, and click a player to kick, ban or op them. Without squaremap, players are drawn on a block grid using RCON.
- **Players**: who is online and where they are, plus everyone the server has seen, ops, the whitelist and bans. Kick (with a reason), ban for 1 hour, 1 day, 7 days or permanently, unban, op or deop, and whitelist from each row.
- **Quick Commands**: one-click time of day, weather and difficulty; switches for common game rules; save the world, whitelist on or off, broadcast, or run any command. A "Recent actions" list shows who ran what.
- **Config**: server settings, game rules and Paper settings as a form. Each setting has a plain title, a one-line description and the right control (switch, number, slider, dropdown or text), plus a tag saying whether it applies right away or needs a restart. Search across all settings, review a before-and-after list, then save. See [Config](#config) below.
- Every action is checked against its own subuser permission and written to the server's Activity log.

## Requirements

- Pelican v1.0.0-beta34 or newer (Laravel 13, Filament 5). Plugins are still a beta feature of Pelican.
- A Minecraft Java server, vanilla or Paper. Game rule names from both before and after 1.21.11 are handled.
- **RCON** for live data. Without it, actions still work, but Overseer can't list who's online or read game rule values. In `server.properties`:

  ```properties
  enable-rcon=true
  rcon.password=<a long random password>
  rcon.port=25575
  broadcast-rcon-to-ops=false
  ```

  The panel connects to that port, so it must be reachable from the panel host. Add the port as an extra allocation on the server in Pelican, and don't expose it to the internet: RCON is not encrypted.

### Live Map (optional)

Install [squaremap](https://github.com/jpenilla/squaremap) on the Minecraft server (Paper, Fabric or NeoForge). Its built-in web server listens on port 8080 by default (`settings.internal-webserver.port` in squaremap's `config.yml`). Add that port as an allocation on the server in Pelican, like the RCON port.

The browser never connects to squaremap: the panel fetches the tiles and passes them on, so the map works on an HTTPS panel and the port does not need to be open to the internet. The panel only ever connects to the server's own address on one of its own allocated ports.

## Install

The easiest way is the zip from the [latest release](https://github.com/headdetect/pelican-minecraft-overseer/releases/latest). In the panel go to **Admin → Plugins**, choose **Import from file**, upload `overseer-<version>.zip`, then press **Install** on the Overseer row. To update later, import the newer zip the same way.

Or copy this repository into your panel's plugin folder as `overseer` (the folder name must match the plugin id):

```bash
cd /var/www/pelican/plugins
git clone https://github.com/headdetect/pelican-minecraft-overseer overseer
cd /var/www/pelican
php artisan p:plugin:install overseer
```

Installing runs the plugin's two migrations. Pelican's queue worker and scheduler need to be running: the scheduler lifts timed bans.

Then give people access under the server's **Users** page. The Overseer tab has one permission per action:

| Permission | Lets them |
| --- | --- |
| map-view | Open the Live Map |
| players-view | Open the Players page |
| players-kick | Kick |
| players-ban | Ban and unban |
| players-op | Op and deop |
| players-whitelist | Change the whitelist |
| commands-world | Time, weather, difficulty, game rules, save |
| commands-ops | Whitelist on or off, broadcast, any command |
| config-view | Open the Config page |
| config-edit | Change settings on the Config page |

The server owner and panel admins always have every permission.

## Settings

Optional `.env` values on the panel:

| Key | Default | What it does |
| --- | --- | --- |
| `OVERSEER_RCON_HOST` | allocation IP, or the node's address | Host the panel dials for RCON |
| `OVERSEER_RCON_TIMEOUT` | `2.0` | Seconds to wait for RCON |
| `OVERSEER_MAP_HOST` | same as RCON | Host the panel dials for squaremap |
| `OVERSEER_MAP_TIMEOUT` | `3.0` | Seconds to wait for squaremap |
| `OVERSEER_MAP_REFRESH` | `5` | Seconds between player position updates on the map |
| `OVERSEER_RECENT_ACTIONS` | `10` | Rows in "Recent actions" |
| `OVERSEER_CONFIG_BACKUPS` | `10` | Old copies of each config file kept on the server |

## Config

The Config page has three sections:

- **Server settings** (`server.properties`). Only settings that are in the server's file are shown, so a setting newer Minecraft versions moved elsewhere (such as `pvp`, which is a game rule in recent versions) disappears here and shows up under Game rules. Turn on **Show advanced** to see the rest of the file as plain text boxes. The server's address and port are left out because Pelican sets them, and secrets are never sent to the browser: the RCON password box is write-only.
- **Game rules**, read and changed with `/gamerule` over RCON, so they need the server running with RCON on. Both the 1.21.11+ names and the older ones work, and a rule your version doesn't have is hidden.
- **Paper settings** (`config/paper-world-defaults.yml`), only on Paper servers.

Saving changes only the lines you changed, so comments and settings Overseer doesn't know about stay as they were. Before each save the old file is copied to `.overseer/backups/` on the server. Difficulty, default game mode, whitelist and AFK kick also apply right away through the console when the server is running. Anything else that needs a restart shows a **Restart to apply** button after saving.

To add a setting, add one entry to the right file in `resources/schemas/`.

## Development

```bash
php tests/run.php
```

To build the plugin zip locally, run `bin/build-zip.sh`; it lands in `dist/`. Every merge to `main` publishes a GitHub Release with the zip attached. The version is the newest release with its last number bumped (0.1.0, then 0.1.1, and so on). To start a new minor or major version, raise `version` in `plugin.json` and the next merge uses it. Pushing a tag such as `v0.2.0`, or running the **Release** workflow from the Actions tab with a version, releases that exact version.

The tests cover input checking, `server.properties` reading and writing, Paper YAML edits, the config schemas, reply parsing, reading squaremap's config and JSON, and the RCON client (against a small fake RCON server). The Filament pages need a running Pelican panel to try. The map script (`resources/map/live-map.js`) has no dependencies and is inlined into the page, so nothing needs building.
