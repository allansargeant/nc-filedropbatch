# File Drop Batch

> **AI-assisted project.** This codebase was created with [Claude Code](https://claude.com/claude-code)
> (Anthropic), directed and reviewed by a human author — including the code, the docs,
> and the visuals in this README. Review it yourself before relying on it in production,
> same as you would for any code.

A Nextcloud app for theatre/event production teams. Upload a CSV of sessions and it will:

![Architecture: CSV in, folders/shares/accounts out](docs/architecture.svg)

- create a nested folder per row (`Theatre / Date / "Start Time - Presenter"`),
- create an upload-only "file drop" public link for each folder, with a single expiry date applied to the whole batch,
- email each presenter their link,
- hand back the same CSV with a `File Drop Link` column added.

Optionally, it can also:

- create a set of shared root folders (`Holding slides`, `fonts`, `schedules`, `all show`, plus custom names) once at the top of the batch, and
- create a Nextcloud account per distinct theatre in the CSV, scoped to its own theatre folder plus the shared root folders (not other theatres), with a generated password saved to a downloadable CSV. Creating accounts is restricted to admins/subadmins.
- once a batch's file-drop links pass their expiry, automatically mirror the whole base folder to a separate Nextcloud instance (e.g. a server taken to the event site) via `rclone` over WebDAV - see [Site-server sync](#site-server-sync) below.

## The upload page

![The upload form and results tables, with sample data](docs/screenshots/app-preview.png)

*A static mockup built from the app's real CSS/markup with illustrative sample data (not a live capture) - see [`docs/mockups/app-preview.html`](docs/mockups/app-preview.html).*

## Input CSV format

```
Date, Theatre, Start Time, presenter name, presenter email
```

## Installing

Copy (or symlink) `custom_apps/filedropbatch` into your Nextcloud instance's `custom_apps` (or `apps`) directory, then:

```
occ app:enable filedropbatch
```

The app needs no Composer dependencies - it only uses Nextcloud's built-in OCP APIs (`IRootFolder`, `Share\IManager`, `IUserManager`, `IMailer`).

### Note on link expiry

Nextcloud's public link share expiration is date-only: the server truncates both the expiration date and "now" to midnight before comparing, so the earliest valid expiry is always tomorrow, and the share effectively expires at 00:00 on the chosen date regardless of what time you might otherwise expect. This is a platform behavior, not something this app can override.

## Site-server sync

Once a batch's file-drop links pass their expiry date (no more uploads expected), a background job can mirror the whole base folder — root folders, theatre folders, everything collected — to a second, separate Nextcloud instance over WebDAV, using [`rclone`](https://rclone.org/). This is meant for the case where the event venue itself has no reliable link back to this server: bring a "site server" pre-loaded with everything up to the last sync before heading out.

**Requirements:** `rclone` installed on this server and reachable on `$PATH` (or point at it explicitly in settings), and PHP allowed to spawn external processes (`proc_open`) — normal on a self-hosted box you control, often disabled on shared hosting. This is a deliberate exception to keeping this app free of external dependencies, scoped to this one optional feature.

**Configure it** under Nextcloud's admin Settings → File Drop Batch: the remote instance's URL, a username, and an **app password** you create on that remote instance (Settings → Security → "Create new app password" — never use a real account password here), plus an optional remote base path and an "automatically sync on expiry" toggle. A "Sync now" button runs it on demand regardless of that toggle, useful for testing the connection.

**How it authenticates:** the destination (remote) leg uses the app password you configure, encrypted at rest. The source (this server) leg never stores a password at all — each sync mints a fresh, short-lived Nextcloud app token for the batch's owner, uses it for the local WebDAV connection, and revokes it immediately afterward, success or failure.

**Scheduling:** a small database table (`fdb_batches`) records each batch's owner, base folder, and expiry date when it's created. A background job (piggybacking on Nextcloud's own cron, so no extra system cron entry is needed) checks roughly every 15 minutes for batches whose expiry has passed and haven't been synced yet, and triggers one whole-folder sync per distinct (user, base folder) pair — not a sync per batch, since `rclone sync` only transfers deltas anyway. A failed sync is logged and left for retry on the next run rather than marked done.

**Set the "local WebDAV base URL" explicitly** if this instance runs behind Docker, a reverse proxy, or anything else where the request that reaches PHP doesn't carry the same hostname the outside world uses. Auto-detection (via Nextcloud's own URL generator) reflects whatever `overwrite.cli.url`/trusted-domain resolution produces for a CLI/cron context, which in a typical `docker-compose` setup resolves to the *host-mapped* address (e.g. `localhost:8080`) rather than the address reachable from *inside* the container where `rclone` actually runs (e.g. plain `http://localhost`, since Apache listens on port 80 internally) - confirmed while testing this exact feature against the bundled dev stack. If the source leg of a sync fails with a connection error despite the destination being reachable, this is almost always why.

## Local dev environment

`docker-compose.yml` brings up a disposable Nextcloud + MariaDB + MailHog stack for development. Credentials are read from a `.env` file (gitignored) rather than committed:

```
cp .env.example .env
# edit .env and set real passwords

docker compose up -d
docker compose exec -u root app chown www-data:www-data /var/www/html/custom_apps
docker compose exec -u www-data app php occ maintenance:install \
  --database mysql --database-host db --database-name nextcloud \
  --database-user nextcloud --database-pass "$(grep DB_PASSWORD .env | cut -d= -f2)" \
  --admin-user admin --admin-pass "$(grep NEXTCLOUD_ADMIN_PASSWORD .env | cut -d= -f2)"
docker compose exec -u www-data app php occ config:system:set mail_smtpmode --value=smtp
docker compose exec -u www-data app php occ config:system:set mail_smtphost --value=mailhog
docker compose exec -u www-data app php occ config:system:set mail_smtpport --value=1025
docker compose exec -u www-data app php occ config:system:set mail_smtpauth --value=0 --type=integer
docker compose exec -u www-data app php occ app:enable filedropbatch
```

Nextcloud is then available at `http://localhost:8080` (log in with the admin user/password you set in `.env`) and MailHog at `http://localhost:8025`.

The manual `chown`/`maintenance:install` steps are needed because the `custom_apps` directory ships owned by `root` in the official image, which makes the container's own automatic installer fail on a fresh volume.

Two sample CSVs are included: `sample-sessions.csv` (a clean golden-path file) and `sample-sessions-edge-cases.csv` (duplicate rows, invalid email, bad date/time, missing fields, to exercise the success/partial/error paths).

### Testing the site-server sync locally

`docker-compose.site-server.yml` stands up a second, independent Nextcloud instance to act as the "site server" - useful for exercising the rclone sync feature end-to-end without a real second machine. It needs its own set of `.env` values (`SITE_DB_ROOT_PASSWORD`, `SITE_DB_PASSWORD`, `SITE_ADMIN_USER`, `SITE_ADMIN_PASSWORD` - see `.env.example`) and runs as a separate compose project so it doesn't collide with the primary stack:

```
docker compose --env-file .env -p fdb-site -f docker-compose.site-server.yml up -d
```

This exposes the site instance at `http://localhost:8081`. From inside the primary `app` container, reach it via Docker Desktop's `http://host.docker.internal:8081` (its published port isn't reachable via `localhost` from another container) - that's the URL to enter as the "Remote Nextcloud URL" in admin settings, with an app password created on the site instance (Settings → Security) as the remote password. `rclone` itself isn't bundled in the official Nextcloud image, so install it into the primary container for testing: `docker compose exec -u root app bash -c "curl -s https://rclone.org/install.sh | bash"`.

To simulate a batch's expiry actually having passed (new batches always require an expiry of at least tomorrow, so none will be naturally due yet), backdate it directly: `docker compose exec db mysql -u nextcloud -p"$DB_PASSWORD" nextcloud -e "UPDATE oc_fdb_batches SET expiry_date = '2020-01-01' WHERE id = 1;"`. Then either wait for real cron activity, or trigger the specific job immediately for testing with `occ background-job:list` (to find its id) followed by `occ background-job:execute <id>` - `cron.php` alone only advances one due job per invocation (by design, matching how a real system cron ticks over time), so repeated manual invocations aren't a reliable way to test a specific job on demand.

## Roadmap / TODO

- [ ] Replace the static README mockup with a live screenshot captured from a running instance (the local `docker-compose` dev stack above stands one up).

## License

AGPL-3.0, matching Nextcloud's own app licensing convention.
