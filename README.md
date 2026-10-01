# Underseer

A [Pelican](https://pelican.dev) panel plugin for running a Minecraft Java server without memorising console commands.

**Working now**

- **Players**: who is online and where they are, plus everyone the server has seen, ops, the whitelist and bans. Kick (with a reason), ban for 1 hour, 1 day, 7 days or permanently, unban, op or deop, and whitelist from each row.
- **Quick Commands**: one-click time of day, weather and difficulty; switches for common game rules; save the world, whitelist on or off, broadcast, or run any command. A "Recent actions" list shows who ran what.
- Every action is checked against its own subuser permission and written to the server's Activity log.

**Planned**: live map (squaremap or BlueMap), and a config editor where each setting has a plain title, a one-line description and the right control. See the [spec](https://claude.ai/code/artifact/cba3745a-6cac-4b68-bd70-2ddb0f22a71f).

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
| `UNDERSEER_RECENT_ACTIONS` | `10` | Rows in "Recent actions" |

## Development

```bash
php tests/run.php
```

The tests cover input checking, `server.properties` parsing, reply parsing and the RCON client (against a small fake RCON server). The Filament pages need a running Pelican panel to try.
