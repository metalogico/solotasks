# Operating SoloTasks

Run these commands from the directory containing `compose.yaml` and your private `.env`. When using an override or a different Compose project name, include the same options in every command.

## Persistent data

The `data` volume stores `/var/lib/sh-solotasks/database.sqlite`, containing projects, milestones and tasks. The `storage` volume stores application working files, sessions, cache and compiled views under `/var/www/html/storage`.

Keep your private `.env`, including `APP_KEY` and the login configuration, with the matching application version. Backup and recovery are managed entirely by external infrastructure.

Recreating the app preserves both volumes. Never use `docker compose down -v` or `migrate:fresh` on an installation whose data you want to keep.

## Updates and rollback

Follow [UPGRADING.md](UPGRADING.md). Preserve `.env`, `APP_KEY`, the Compose project name and both data volumes. Retain the previous image version. Pull the selected image, stop the app, then recreate it. Verify login, task editing and both calendars.

Released migrations are incremental. After a schema change, rollback requires recovering the matching pre-update data through external infrastructure and selecting the previous image.

## Change the login

Generate a new bcrypt hash:

```sh
docker compose run --rm --no-deps --entrypoint php app artisan solotasks:password
```

Update `SOLOTASKS_PASSWORD_HASH` in `.env`, keeping single quotes around the hash. You may also change `SOLOTASKS_USERNAME`. Recreate the app:

```sh
docker compose up -d --force-recreate --wait
```

Changing either credential invalidates existing sessions. There is no email-based recovery flow.

## Separate instances

To run another instance from the same checkout, use a different Compose project name, image tag, environment file and port. `SOLOTASKS_ENV_FILE` selects the file passed to the containers; `--env-file` selects Compose interpolation values. Use both consistently so an alternate instance never reads your primary `.env`.

For example, copy `.env.example` to `.env.second` and configure its own key, login, URL and port. In that file set `SOLOTASKS_ENV_FILE=.env.second`, `SOLOTASKS_IMAGE=ghcr.io/metalogico/solotasks:latest` and a distinct `SESSION_COOKIE`, such as `solotasks_second_session`. Cookies are shared across ports on the same hostname, so each instance needs its own cookie name or hostname. Then use:

```sh
docker compose --env-file .env.second -p solotasks-second pull
docker compose --env-file .env.second -p solotasks-second up -d --wait
```

Each project gets its own `data` and `storage` volumes. Keep the same project name for its lifetime; changing the name selects different volumes rather than moving data.
