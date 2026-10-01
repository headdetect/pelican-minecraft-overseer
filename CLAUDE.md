# Overseer

A Pelican panel plugin (PHP, Laravel, Filament). The repository is the plugin
directory: `plugin.json` at the top, the code in `src/`.

## Test a change

Run the unit tests and look at the change in a real panel:

```bash
dev/test                 # tests/run.php, with the PHP in the dev panel
dev/shot players         # screenshot of an Overseer page, plus errors
```

Read the PNG that `dev/shot` saves in `dev/.data/shots/`. It prints console
errors and new Laravel log lines too. If the dev stack is not running, start it
with `dev/up.sh`. `dev/README.md` lists every tool.

The panel loads the source from this checkout, so there is no build or upload
step. If you add or remove a Filament page, run
`dev/artisan filament:cache-components`. If you add a migration, run
`dev/artisan migrate`.

## Deploying

The production panel is in a separate repository, `nurps-minecraft`. Its
`./deploy.sh plugin` command copies this checkout to the live panel. Do not run
it unless Brayden asks.
