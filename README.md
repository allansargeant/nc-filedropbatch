# File Drop Batch

A Nextcloud app for theatre/event production teams. Upload a CSV of sessions and it will:

- create a nested folder per row (`Theatre / Date / "Start Time - Presenter"`),
- create an upload-only "file drop" public link for each folder, with a single expiry date applied to the whole batch,
- email each presenter their link,
- hand back the same CSV with a `File Drop Link` column added.

Optionally, it can also:

- create a set of shared root folders (`Holding slides`, `fonts`, `schedules`, `all show`, plus custom names) once at the top of the batch, and
- create a Nextcloud account per distinct theatre in the CSV, scoped to its own theatre folder plus the shared root folders (not other theatres), with a generated password saved to a downloadable CSV. Creating accounts is restricted to admins/subadmins.

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

## License

AGPL-3.0, matching Nextcloud's own app licensing convention.
