# AGENTS.md — bringing an LLM up to speed on File Drop Batch

Orientation for an AI assistant (or a new human) picking this project up cold. There is no
`CLAUDE.md` here; this is the entry point.

---

## 1. What this is

A **Nextcloud app for theatre/event production teams**. You build a show — from a CSV, typed
in the browser, or live-synced from a Google Sheet — and it:

- creates a nested folder per session (`Theatre / Date / "Start Time - Presenter"`),
- creates an **upload-only public "file drop" link** per folder, with one expiry date across
  the whole batch,
- emails each presenter their link,
- hands back a CSV with a `File Drop Link` column added.

Optionally it also creates shared root folders, creates a **Nextcloud account per theatre**
scoped to its own folder, mirrors an expired batch to a site server over `rclone`/WebDAV, and
tracks every session in a persistent, editable list.

PHP, Nextcloud app. Public repo.

## 2. This handles real people's credentials and public links — take it seriously

Unusually for this fleet, this app does things with genuine security weight:

- It **creates public upload links** that anyone with the URL can write to.
- It **creates Nextcloud user accounts with generated passwords**, written to a downloadable
  CSV. Account creation is restricted to admins/subadmins — **keep it that way**.
- It **emails presenters** — real outbound mail to real people.
- It **scopes each theatre account to its own folder and the shared roots, deliberately not
  to other theatres.** That isolation is a feature, not an accident. Don't widen it casually.
- `PathSanitizer` exists because folder names are built from user-supplied session data
  (presenter names, theatre names). It has its own unit test. **Never bypass it** when
  constructing a path — that's a directory-traversal vector straight into a file server.

## 3. The shared-CSV contract

The **same session CSV** drives this app and **`resolve-configurator`** (a separate Python
repo that scaffolds the DaVinci Resolve project for the same event).

```
   session CSV
   /        \
nc-filedropbatch    resolve-configurator
(collects uploads)  (builds the edit project)
```

`lib/Service/CsvReader.php` is this side of that contract. **If you change the column
meaning, check `resolve-configurator` too** — neither repo's tests would catch a divergence
on their own.

## 4. Layout

```
custom_apps/filedropbatch/
  appinfo/routes.php          Route table (loaded by convention)
  lib/AppInfo/Application.php App bootstrap / DI registration
  lib/Controller/             HTTP endpoints: Batch, Session, Sheet,
                              GoogleAuth, Page, AdminSettings
  lib/Service/                The real logic:
      BatchProcessorService     orchestrates a batch build
      CsvReader / CsvWriter     the shared CSV contract
      FolderService             folder + share creation
      PathSanitizer             SECURITY - path construction
      MailService               presenter emails
      GoogleAuthService / GoogleSheetsService
  lib/Db/                     Batch, Session, Sheet + their Mappers
  lib/BackgroundJob/          SyncExpiredBatchesJob, SyncGoogleSheetsJob
  lib/Migration/              Versioned schema migrations
  tests/Unit/                 CsvReaderTest, PathSanitizerTest
```

**Nextcloud loads controllers, migrations and `routes.php` by convention/DI**, so they look
"unreferenced" to a naive dead-code scan. They are not. Don't remove them on that basis.

## 5. Working on it

CI runs lint, test and release workflows. Migrations are versioned and dated
(`Version010400Date20260718000000.php`) — **add a new migration rather than editing an
applied one**; an edited migration silently skips on instances that already ran it.

Background jobs (`SyncExpiredBatchesJob`, `SyncGoogleSheetsJob`) run on Nextcloud's cron.
Behaviour that depends on them won't appear in a plain request/response test.

## 6. Conventions

- Public repo. "Commit" means commit **and** push.

## 7. Related

- **`resolve-configurator`** — the other consumer of the session CSV.
