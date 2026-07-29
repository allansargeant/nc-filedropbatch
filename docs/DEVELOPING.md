# File Drop Batch — Developing

A Nextcloud app in plain PHP with **no Composer dependencies** — it uses only Nextcloud's own
OCP APIs (`IRootFolder`, `Share\IManager`, `IUserManager`, `IMailer`, `IClientService`,
`ISecureRandom`, `ICrypto`). Keep it that way; the one deliberate exception is `rclone`, an
external binary used only by the optional site-server sync.

The [README](../README.md) has the full local dev-stack and site-server-test recipes, and they
are not repeated here. This is the model, the rules, and what will bite you.

---

## 1. This app has real security weight — read this first

Unusually for a small app, this one does things with genuine consequences outside the server:

- **It creates public upload links.** Anyone with the URL can write into that folder.
- **It creates Nextcloud accounts with generated passwords**, written to a downloadable CSV.
  Account creation is restricted to **admins and subadmins** — `BatchController` checks
  `canManageTheatreUsers()` before honouring `create_users`. **Keep that restriction.**
- **It sends real outbound email to real people**, with no preview and no undo.
- **It scopes each theatre account to its own folder plus the shared roots, deliberately not to
  other theatres.** That isolation is a feature. Don't widen it casually.
- **`rclone sync` deletes at the destination.** See §5.

### `PathSanitizer` is not optional

Folder names are built from **user-supplied session data** — presenter names, theatre names,
values that arrive from an uploaded CSV or a Google Sheet. `PathSanitizer::sanitizeSegment()`
exists because of that, and it has its own unit test.

> **Never bypass it when constructing a path.** That is a directory-traversal vector straight
> into a file server. Every segment — base folder, theatre, date, leaf, root folder name — goes
> through it, and any new path-building code must too.

It **replaces** forbidden characters rather than stripping them, so distinct inputs don't
collapse into one folder (`10:00` vs `1000`). Preserve that property if you change it.

`UserService::sanitizeUsername()` is the equivalent for account names, and has the collision
caveat documented in [USER-GUIDE.md §4](USER-GUIDE.md) — two theatre names can map to one
username, and the second silently reuses the first's account.

Passwords come from `ISecureRandom` with a **Fisher–Yates shuffle using `random_int()`** —
`str_shuffle()` is explicitly not used because it isn't cryptographically secure. The alphabet
deliberately excludes ambiguous glyphs (`I`, `l`, `0`, `1`).

The remote sync password is **encrypted at rest** via Nextcloud's `ICrypto`; the *source* leg
stores nothing at all, minting a short-lived app token per sync and revoking it in a `finally`.

---

## 2. The shared-CSV contract

The **same session CSV** drives this app and
[resolve-configurator](https://github.com/allansargeant/resolve-configurator), which scaffolds
the DaVinci Resolve project for the same event.

```
                session CSV
                /         \
     nc-filedropbatch    resolve-configurator
     (collects uploads)  (builds the edit project)
```

`lib/Service/CsvReader.php` is this side of it. **If you change what a column means, check
resolve-configurator too** — neither repo's tests would catch a divergence on their own. The
contract is written out in [API.md §2](API.md#2-the-session-csv-contract).

---

## 3. Layout, and why nothing looks referenced

```
custom_apps/filedropbatch/
  appinfo/routes.php          route table — loaded by convention
  appinfo/info.xml            app metadata + <dependencies> (PHP 8.1+, Nextcloud 27–31)
  lib/AppInfo/Application.php bootstrap / DI registration
  lib/Controller/             Batch, Session, Sheet, GoogleAuth, Page, AdminSettings
  lib/Service/                the real logic:
      BatchProcessorService     orchestrates a batch build
      SessionService            one session: validate → folder → share → email
      CsvReader / CsvWriter     the shared CSV contract
      FolderService             folder creation, unique leaves
      ShareService              file-drop link + user shares
      PathSanitizer             SECURITY — path construction
      UserService               theatre accounts, password generation
      MailService               presenter emails
      RcloneSyncService         site-server mirror
      SheetSyncService, GoogleAuthService, GoogleSheetsService
  lib/Db/                     Batch, Session, Sheet + Mappers
  lib/BackgroundJob/          SyncExpiredBatchesJob, SyncGoogleSheetsJob
  lib/Migration/              versioned schema migrations
  tests/Unit/                 CsvReaderTest, PathSanitizerTest
```

> **Nextcloud loads controllers, migrations and `routes.php` by convention and DI**, so they look
> "unreferenced" to a naive dead-code scan. **They are not. Don't remove them on that basis.**

### The processing pipeline

`BatchController` → `BatchProcessorService::processBatch()` → per row →
`SessionService::createSession()`.

`SessionService` is where the three-status outcome is decided, in a fixed order:

1. `validateFields()` — missing field, unparseable date, unparseable time → **`error`**, nothing
   created.
2. Folder creation fails → **`error`**.
3. Share creation fails → **`error`**, but the folder now exists.
4. Email address fails `FILTER_VALIDATE_EMAIL` → **`partial`**.
5. Mailer throws → **`partial`**.
6. Otherwise **`success`**.

Everything after step 1 is wrapped and logged rather than thrown, so **one bad row never aborts
the batch.** Preserve that: a 90-row batch that dies on row 4 is worse than one that reports
four errors.

Only `success` and `partial` rows are persisted. `error` rows leave no database trace.

**Manual entry and Google Sheets both funnel through the same `CsvReader::parseRows()` and the
same `BatchProcessorService`**, so all three input paths behave identically by construction. When
adding an input path, join it at `parseRows()` — don't reimplement validation.

`ensureRootFoldersAndTheatreUsers()` is shared by `processBatch()` and `SheetSyncService`
specifically so the "create an account per theatre" setting can't diverge between them. It is
**idempotent** and safe to call on every run.

---

## 4. Migrations

Versioned and dated: `Version010400Date20260718000000.php`.

> **Add a new migration rather than editing an applied one.** An edited migration **silently
> skips** on any instance that already ran it — so it will work on your fresh dev stack and do
> nothing on the production server you actually care about.

---

## 5. Background jobs

`SyncExpiredBatchesJob` and `SyncGoogleSheetsJob` run on **Nextcloud's own cron**, which means
**behaviour that depends on them will not appear in a plain request/response test.**

To exercise a specific job on demand, use `occ background-job:list` to find its id then
`occ background-job:execute <id>`. **`cron.php` alone only advances one due job per invocation**
— by design, matching how a real system cron ticks over time — so repeated manual `cron.php`
calls are not a reliable way to test one job.

To make a batch look expired (new batches always require an expiry of at least tomorrow, so none
is ever naturally due), backdate `oc_fdb_batches.expiry_date` directly — the README has the SQL.

### The sync is destructive

`RcloneSyncService::syncBaseFolder()` runs **`rclone sync`, not `copy`**, with
`--create-empty-src-dirs`. `sync` makes the destination *match* the source: **files at the
destination that aren't in the source are deleted.** That is intentional for a mirror, and it
means the site server must be treated as read-only. If you ever change this to allow two-way
work, it needs to stop being `sync`.

Failed syncs are logged, recorded via `recordResult('error', …)`, and **left for retry** rather
than marked done.

---

## 6. Tests

```bash
cd custom_apps/filedropbatch
phpunit
```

Covers only the pure logic with no Nextcloud runtime dependency: **`PathSanitizer` and
`CsvReader`**. No Nextcloud instance, no database, no Composer install needed. CI runs this plus
a lint job that `php -l`s every file and checks `appinfo/info.xml` is well-formed.

**Everything else is covered by manual verification against a real instance.** Controllers and
services depend on `IRootFolder`, `Share\IManager`, `IUserManager` and friends, and there is no
harness for them here. Be honest about that in commit messages: "tests pass" means the two pure
units pass, not that the batch pipeline was exercised.

Two sample CSVs exist for manual runs: `sample-sessions.csv` (golden path) and
`sample-sessions-edge-cases.csv`, which deliberately contains duplicate rows, an invalid email,
a bad date, a bad time and a missing field — one row for each of the `success`/`partial`/`error`
paths. **Use it after changing anything in the pipeline.**

The dev stack (`docker-compose.yml`) includes MailHog at `:8025`, so presenter emails can be
exercised end-to-end without sending anything real. **Use it** — this app emails people.

---

## 7. Conventions

- Public repo, AGPL-3.0 (matching Nextcloud's app licensing convention).
- "Commit" means commit **and** push.
- New user-facing text should keep the README's posture: AI-assisted, human-directed, review
  before production. Don't quietly upgrade the claim.

---

## See also

- [API.md](API.md) — routes, CSV contract, config keys, tables, jobs
- [USER-GUIDE.md](USER-GUIDE.md) — the operator view, including the day-first date trap
- [README](../README.md) — install, dev stack, site-server test recipe
- [`AGENTS.md`](../AGENTS.md) — LLM onboarding
