# Operating SoloTasks

Run these commands from the directory containing `compose.yaml` and your private `.env`. When using an override or a different Compose project name, include the same options in every command.

## Persistent data

The `data` volume stores `/var/lib/sh-solotasks/database.sqlite`, containing projects, milestones and tasks. The `storage` volume stores application working files, sessions, cache and compiled views under `/var/www/html/storage`.

Back up both volumes and your private `.env` using your backup tools. Keep `APP_KEY`, the login configuration and the matching application image tag or digest with each backup.

Recreating the app preserves both volumes. Never use `docker compose down -v` or `migrate:fresh` on an installation whose data you want to keep.

## Updates and rollback

Before updating, back up both volumes and `.env`, and record the current image tag or digest. To select a specific version, set `SOLOTASKS_IMAGE` in `.env` to its published image reference. Preserve `APP_KEY`, the Compose project name and the volume configuration.

```sh
docker compose config --quiet
docker compose pull
docker compose stop
docker compose up -d --wait --remove-orphans
docker compose ps
```

Database migrations run automatically at startup. After updating, verify login, task editing and both calendars. If startup fails, inspect `docker compose logs --tail=100 app`.

To roll back after a schema change, stop the app, restore the matching pre-update volumes and `.env` from your backup, select the previous image, then start it again. Keep the same application key and Compose project name.

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

## MCP token management

MCP uses a separate credential from the browser login. Set the token directly in `SOLOTASKS_MCP_TOKEN` in your private `.env`; clients send the same value in `Authorization: Bearer <token>` to `/mcp/solotasks`. An empty token disables access, and a missing or incorrect bearer token is rejected. The MCP token does not grant access to the browser API.

To enable or rotate access, generate a fresh token:

```sh
docker compose run --rm --no-deps --entrypoint php app artisan solotasks:mcp-token
```

Copy the displayed `SOLOTASKS_MCP_TOKEN=…` line to `.env`, replacing any previous token, then apply it:

```sh
docker compose up -d --force-recreate --wait
```

Update each MCP client to send the new token. The previous token stops working once the new configuration is loaded. Generation alone does not change configuration or revoke the existing token.

To disable access, clear `SOLOTASKS_MCP_TOKEN` in `.env` and recreate the app with the same command. Changing the personal username or password does not affect MCP access. Keep tokens out of repository files and retain the token with your private installation configuration.

For local development without Docker, run `php artisan solotasks:mcp-token`, update `.env`, clear any cached configuration with `php artisan config:clear` and restart long-running application processes. Docker passes the value through its existing `env_file`; no additional service or port is required.

## Separate instances

To run another instance from the same checkout, use a different Compose project name, image tag, environment file and port. `SOLOTASKS_ENV_FILE` selects the file passed to the containers; `--env-file` selects Compose interpolation values. Use both consistently so an alternate instance never reads your primary `.env`.

For example, copy `.env.example` to `.env.second` and configure its own key, login, URL and port. In that file set `SOLOTASKS_ENV_FILE=.env.second`, `SOLOTASKS_IMAGE=ghcr.io/metalogico/solotasks:latest` and a distinct `SESSION_COOKIE`, such as `solotasks_second_session`. Cookies are shared across ports on the same hostname, so each instance needs its own cookie name or hostname. Then use:

```sh
docker compose --env-file .env.second -p solotasks-second pull
docker compose --env-file .env.second -p solotasks-second up -d --wait
```

Each project gets its own `data` and `storage` volumes. Keep the same project name for its lifetime; changing the name selects different volumes rather than moving data.
