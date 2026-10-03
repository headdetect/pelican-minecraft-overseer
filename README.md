# Overseer

A [Pelican](https://pelican.dev) panel plugin for running a Minecraft Java server without memorising console commands.

**Working now**

- **Overview**: CPU, memory and disk use, and Save, Broadcast and Command buttons. A live map from squaremap shows everyone online, refreshed every 5 seconds. Switch between Overworld, Nether and End, drag and zoom, and click a player to kick, ban, op them or change their game mode. Click anywhere else to see that spot's coordinates, ground height included, and teleport a player there. Without squaremap, players are drawn on a block grid using RCON. Beside the map:
  - Live chat, joins and leaves, with a box to message everyone.
  - Who is online now. Dead players show as Dead, and spectators are left out. Click a player to find them on the map.
  - The game time with a day or night icon, and one-click time of day and weather.
  - The Minecraft version, the modpack with a link to its Modrinth or CurseForge page, and uptime. The modpack comes from `modrinth.index.json`, which the Modrinth generic egg leaves in the server folder, or from the [Modpack Manager](https://hub.pelican.dev/plugins/modpack-manager) plugin.
  - Recent actions, below the map.
- **Players**: everyone who has played on the server and the whitelist, with when each was last online, plus ops and bans. Each player has an actions menu:
  - Op or deop, and add to or remove from the whitelist.
  - Give an item, or teleport to another player or to coordinates. These need the player online.
  - Kick with a reason, ban for 1 hour, 1 day, 7 days or for good, and unban.
- **Config**: server settings, game rules and Paper settings as a form, with a plain title and description for each setting. Change what you need, then save from the top of the page, or save and restart when a change only applies on restart. An undo icon resets a setting to its vanilla default. The Files tab lists mod and plugin config files and opens them in the panel's file editor. See [Config](#config) below.
- **Tools**: generate chunks ahead of time with [Chunky](https://modrinth.com/plugin/chunky), with progress, pause, continue and cancel. squaremap draws new chunks on its own, and Redraw the map draws a whole world again when its map is missing areas.
- The Overseer item in the sidebar shows how many players are online. Every Overseer page warns when RCON is off, can't be reached, or is published on a public address.
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

  The panel connects to that port, so it must be reachable from the panel host, but never from the internet. RCON sends its password unencrypted and Minecraft doesn't slow down password guessing, so anyone who reaches the port can try passwords until one works.

  - If the panel can reach the game container directly, set `OVERSEER_RCON_HOST` to that address and don't add an allocation for the port.
  - Otherwise add the port as an extra allocation bound to a private IP, never `0.0.0.0` or a public IP. Docker writes its own firewall rules for published ports, so a ufw rule on the host may not block them.
  - Check from a machine outside the VPS: `nc -vz <server-ip> 25575` must fail.

  Overseer shows a warning on its pages when an allocation publishes the RCON port on a public or `0.0.0.0` address.

### Live Map (optional)

Install [squaremap](https://github.com/jpenilla/squaremap) on the Minecraft server (Paper, Fabric or NeoForge). Its built-in web server listens on port 8080 by default (`settings.internal-webserver.port` in squaremap's `config.yml`). Add that port as an allocation on the server in Pelican, like the RCON port.

squaremap draws only the chunks that exist and updates them as players explore. To draw an existing world now, use Redraw the map on the Tools tab, or run `squaremap fullrender minecraft:overworld` in the server console.

Overseer polls the server over RCON every few seconds while one of its pages is open, and Minecraft logs a "Thread RCON Client ... started" line for each connection. Those lines show in Pelican's console.

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
| map-view | See the Overview: stats, the map, chat and who is online |
| players-view | Open the Players page |
| players-kick | Kick |
| players-ban | Ban and unban |
| players-op | Op and deop |
| players-whitelist | Change the whitelist |
| players-cheat | Change game mode, give items and teleport players |
| commands-world | Set the time and weather, and save the world |
| commands-ops | Broadcast, send chat and run any console command |
| config-view | Open the Config page |
| config-edit | Change settings on the Config page |
| tools | Generate chunks and redraw the map on the Tools page |

The server owner and panel admins always have every permission.

`commands-ops` lets someone run any console command, including `op` and `stop`, so it covers everything the other permissions allow. Only give it to people you'd trust with the console.

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

`script/up` starts a local panel, wings and a Fabric server with Overseer and squaremap installed. [DEVELOPMENT.md](DEVELOPMENT.md) covers the dev stack and every command in `script/`.

```bash
php tests/run.php   # or script/test, with the PHP in the dev panel
```

To build the plugin zip locally, run `bin/build-zip.sh`; it lands in `dist/`. Every merge to `main` publishes a GitHub Release with the zip attached. The version is the newest release with its last number bumped (0.1.0, then 0.1.1, and so on). To start a new minor or major version, raise `version` in `plugin.json` and the next merge uses it. Pushing a tag such as `v0.2.0`, or running the **Release** workflow from the Actions tab with a version, releases that exact version.

The tests cover input checking, `server.properties` reading and writing, Paper YAML edits, the config schemas, reply parsing, reading squaremap's config and JSON, and the RCON client (against a small fake RCON server). The Filament pages need a running Pelican panel to try. The map script (`resources/map/live-map.js`) has no dependencies and is inlined into the page, so nothing needs building.
