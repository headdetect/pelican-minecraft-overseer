# Underseer

A [Pelican](https://pelican.dev) panel plugin for running a Minecraft Java server without memorising console commands.

**Working now**

- **Live Map**: the world map from squaremap with a marker for everyone online, updated every 5 seconds. Switch between Overworld, Nether and End, drag and zoom, and click a player to kick, ban or op them. Without squaremap, players are drawn on a block grid using RCON.
- **Players**: who is online and where they are, plus everyone the server has seen, ops, the whitelist and bans. Kick (with a reason), ban for 1 hour, 1 day, 7 days or permanently, unban, op or deop, and whitelist from each row.
- **Quick Commands**: one-click time of day, weather and difficulty; switches for common game rules; save the world, whitelist on or off, broadcast, or run any command. A "Recent actions" list shows who ran what.
- Every action is checked against its own subuser permission and written to the server's Activity log.

**Planned**: a config editor where each setting has a plain title, a one-line description and the right control.

## Requirements

- Pelican v1.0.0-beta34 or newer (Laravel 13, Filament 5). Plugins are still a beta feature of Pelican.
- A Minecraft Java server, vanilla or Paper. Game rule names from both before and after 1.21.11 are handled.
- **RCON** for live data. Without it, actions still work, but Underseer can't list who's online or read game rule values. In `server.properties`:

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

Copy this repository into your panel's plugin folder as `underseer` (the folder name must match the plugin id):

```bash
cd /var/www/pelican/plugins
git clone https://github.com/headdetect/pelican-minecraft-overseer underseer
cd /var/www/pelican
php artisan p:plugin:install underseer
```

You can also zip the folder and import it under **Admin → Plugins**. Installing runs the plugin's two migrations. Pelican's queue worker and scheduler need to be running: the scheduler lifts timed bans.

Then give people access under the server's **Users** page. The Underseer tab has one permission per action:

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

The server owner and panel admins always have every permission.

## Settings

Optional `.env` values on the panel:

| Key | Default | What it does |
| --- | --- | --- |
| `UNDERSEER_RCON_HOST` | allocation IP, or the node's address | Host the panel dials for RCON |
| `UNDERSEER_RCON_TIMEOUT` | `2.0` | Seconds to wait for RCON |
| `UNDERSEER_MAP_HOST` | same as RCON | Host the panel dials for squaremap |
| `UNDERSEER_MAP_TIMEOUT` | `3.0` | Seconds to wait for squaremap |
| `UNDERSEER_MAP_REFRESH` | `5` | Seconds between player position updates on the map |
| `UNDERSEER_RECENT_ACTIONS` | `10` | Rows in "Recent actions" |

## Development

```bash
php tests/run.php
```

The tests cover input checking, `server.properties` parsing, reply parsing, reading squaremap's config and JSON, and the RCON client (against a small fake RCON server). The Filament pages need a running Pelican panel to try. The map script (`resources/map/live-map.js`) has no dependencies and is inlined into the page, so nothing needs building.
