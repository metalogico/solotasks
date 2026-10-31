# Updates and the first image-based installation

Use `https://github.com/metalogico/solotasks/archive/COMMIT.tar.gz` for a source archive matching a pinned image, replacing COMMIT with the full published main commit SHA.

## Before every update

1. Review the target main commit and migration requirements. Retain the current version/image digest and installation files.
2. Arrange backup and recovery through your external infrastructure. Preserve `.env`, application data and any private TLS state.
3. Download the source archive for the target main commit into a **separate directory**. Compare its Compose and example environment with your deployed configuration. Copy approved configuration changes into the existing installation directory; keep your private `.env`. Retain only overrides compatible with the target Compose configuration, and remove retired operational overrides from your command or `COMPOSE_FILE`.
4. Set `SOLOTASKS_IMAGE=ghcr.io/metalogico/solotasks:sha-COMMIT` in your existing `.env`, replacing COMMIT with the full published main commit SHA. `latest` is also supported, but a pinned commit gives you control over updates. Review new environment variables individually.

Use the same `-p`, `--env-file` and `-f` options on **every** command when your deployment uses them. The abbreviated commands below assume the default files and project name. Stop the app before running migrations.

```sh
docker compose config --quiet
docker compose pull
docker compose stop
docker compose up -d --wait --remove-orphans
docker compose ps
```

Pull before stopping to reduce downtime. If the pull fails, the running version remains available. If startup or a migration fails, stop the app and inspect its logs. The `--remove-orphans` option removes retired service containers belonging to the same Compose project. Successful health checks should be followed by sign-in, checking existing projects, milestones and tasks, and a small edit.

Stopping/recreating containers preserves persistent storage. **Never run `down -v`, `migrate:fresh`, database resets or volume pruning as part of an update.** Updates do not regenerate credentials or keys.

## Existing source-built installations: first transition to published images

Changing the image is safe only when it uses the same storage and a compatible schema. Older development revisions could change migrations in place. The first release does not infer or repair every historical development schema.

- Record the current Compose project name from container labels, actual volume names and bind mount paths, numeric UID/GID, environment file and override files. `docker compose ps` and `docker inspect <container>` help inspect the installation; avoid sharing their secret-bearing output.
- Compare the deployed database schema and migration history with the v1 baseline using isolated data provided by your external recovery process. Run the candidate image in a separate project with separate ports and data mounts. Confirm existing projects, milestones and tasks, authentication and migrations. A production mount must never be used for this rehearsal.
- If the old schema differs, prepare and test a specific incremental migration/import before updating the real stack. Do not delete rows from Laravel's migrations table or reset SQLite's schema version to force startup.
- Keep the real `.env`, `APP_KEY` and login values. Replace only the old local image reference with the GHCR reference. Remove any source-build override from the production command or `COMPOSE_FILE`.
- Preserve all existing data mounts and the exact Compose project name. A different name selects new empty volumes; that does not mean the original data was erased.
- Perform the regular update above only after the rehearsal succeeds. Retain the old image and the data managed by your external recovery process until the new installation is verified.

The local smoke script tests a fresh image-only install and persistence across container recreation. GitHub Actions does not run these tests. It does not inspect or migrate a particular home server's historical database.

## Rollback

After a schema migration, switching back to an older image alone may be unsafe. Stop the app, recover the matching pre-update database, files and configuration through external infrastructure, and select the previous pinned image tag or digest. Rehearse recovery in an isolated project first. Keep the same application key.
