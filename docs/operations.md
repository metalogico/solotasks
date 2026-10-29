# Operating SoloTasks

Run these commands from the directory containing `compose.yaml` and your private `.env`. When using an override or a different Compose project name, include the same options in every command.

## Backups

The scheduler takes a consistent SQLite snapshot with `VACUUM INTO` at 03:00 and 15:00 Europe/Rome. Each file is checked with `PRAGMA integrity_check` and created with private permissions. No automatic retention or deletion policy is applied.

Create a snapshot manually:

```sh
docker compose exec -T --user www-data app php artisan solotasks:backup --no-interaction
```

The command prints a path such as `/backups/solotasks-YYYYMMDD-HHMMSS-ID.sqlite`. Replace `BACKUP.sqlite` below with that filename.

Verify that the snapshot can be restored and its application tables read:

```sh
docker compose exec -T --user www-data app \
  php artisan solotasks:verify-backup /backups/BACKUP.sqlite --no-interaction
```

Verification copies the snapshot to a temporary file, checks SQLite integrity and foreign keys, reads projects, milestones and tasks, and removes the temporary copy. It does not replace the active database.

Export the snapshot to the host:

```sh
mkdir -p backups
chmod 700 backups
docker compose cp app:/backups/BACKUP.sqlite ./backups/
chmod 600 ./backups/BACKUP.sqlite
```

Store exported snapshots on another disk or machine. Preserve the matching `.env`, especially `APP_KEY` and the login configuration, and the source revision or image used by the backup. The database holds functional data; sessions can be recreated by signing in again. Do not copy the live SQLite file while the app is running.

Inspect the schedule and logs:

```sh
docker compose exec -T --user www-data app php artisan schedule:list --no-interaction
docker compose logs --tail=100 scheduler
```

## Backups on another disk

`compose.minipc.yaml` replaces the `backups` named volume with a dedicated existing host directory. It can be used on any server with a mounted backup disk.

Create a folder reserved for SoloTasks backups, set its absolute path as `SOLOTASKS_BACKUP_DIR` in `.env`, and start with both files:

```sh
docker compose -f compose.yaml -f compose.minipc.yaml config --quiet
docker compose -f compose.yaml -f compose.minipc.yaml up -d --wait
```

Docker will not create a missing host directory. Check that the disk is mounted before starting. Initialization assigns the backup directory and its contents to the container's `www-data` user and sets private directory permissions; use a dedicated folder.

Keep both `-f` options in subsequent backup, update and restore commands. Existing snapshots in the named volume are preserved but do not move automatically into the host directory. Export them before switching if you want them in the new location.

## Restore

First verify the chosen snapshot as described above. If restoring an exported file, copy it back into the backup volume while the app container is available:

```sh
docker compose cp ./backups/BACKUP.sqlite app:/backups/BACKUP.sqlite
docker compose exec -T --user root app chown www-data:www-data /backups/BACKUP.sqlite
docker compose exec -T --user root app chmod 600 /backups/BACKUP.sqlite
```

The copied file may belong to a different user. Set its owner to `www-data` so the verification command can read it, while keeping the backup private with mode `600`.

Run the verification command on that imported file. If the current database is readable, create and export one more backup before replacing it.

Stop both services so nothing writes during recovery:

```sh
docker compose stop app scheduler
```

The following helper preserves the current database and any SQLite journal files under a timestamped name, then installs the verified snapshot. Replace `BACKUP.sqlite` in the command. The helper bypasses the normal entrypoint so migrations do not run before the restore.

```sh
docker compose run --rm --no-deps --entrypoint sh app -c '
  set -eu
  test -f /backups/BACKUP.sqlite
  snapshot="${DB_DATABASE}.before-restore-$(date +%Y%m%d-%H%M%S)"
  test ! -e "$snapshot"
  for suffix in "" -wal -shm -journal; do
    if [ -f "$DB_DATABASE$suffix" ]; then
      mv "$DB_DATABASE$suffix" "$snapshot$suffix"
    fi
  done
  cp /backups/BACKUP.sqlite "$DB_DATABASE"
  chown www-data:www-data "$DB_DATABASE"
  chmod 600 "$DB_DATABASE"
'
docker compose up -d --wait
```

Sign in and check projects, tasks, milestones and both calendars. Keep the pre-restore files and exported snapshots until you have checked the recovered installation. Never use `migrate:fresh` or remove data volumes during an update or restore.

On a new machine, first follow the installation steps, using the preserved `.env` and the matching image version, then import and restore the snapshot. Do not generate a replacement `APP_KEY` for an existing installation.

## Updates and rollback

Follow [UPGRADING.md](UPGRADING.md). Create, verify and export a backup, preserve `.env` and `APP_KEY`, and retain the previous image version. Pull the selected image, stop both app and scheduler, then recreate them using the same project name and volumes. Verify login, task editing, calendars and the backup schedule.

Released migrations are incremental. After a schema change, rollback requires the matching pre-update backup as well as the previous image. Do not remove either until the update is verified.

## Change the login

Generate a new bcrypt hash:

```sh
docker compose run --rm --no-deps --entrypoint php app artisan solotasks:password
```

Update `SOLOTASKS_PASSWORD_HASH` in `.env`, keeping single quotes around the hash. You may also change `SOLOTASKS_USERNAME`. Recreate both services:

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

Each project gets its own `data`, `storage` and `backups` volumes. Keep the same project name for its lifetime; changing the name selects different volumes rather than moving data.
