# File Drop Batch — Interfaces

The HTTP routes, the CSV contract, the admin configuration keys, the database tables and the
background jobs.

| § | Interface | Source |
|---|---|---|
| [1](#1-http-routes) | HTTP routes | `appinfo/routes.php`, `lib/Controller/` |
| [2](#2-the-session-csv-contract) | Session CSV in / out | `lib/Service/CsvReader.php`, `CsvWriter.php` |
| [3](#3-admin-configuration-keys) | Admin config keys | `lib/Service/RcloneSyncService.php`, `GoogleAuthService.php` |
| [4](#4-database-tables) | Database tables | `lib/Db/`, `lib/Migration/` |
| [5](#5-background-jobs) | Background jobs | `lib/BackgroundJob/` |

> **This app creates public upload links, Nextcloud accounts with generated passwords, and sends
> real email to real people.** The security notes below are not decorative. See
> [DEVELOPING.md §1](DEVELOPING.md).

---

## 1. HTTP routes

All under the app's route prefix. Responses are `DataResponse` JSON unless noted.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `GET` | `/` | user | the app page |
| `POST` | `/batch` | user | build a batch from an uploaded CSV |
| `POST` | `/batch/manual` | user | build a batch from browser-entered rows |
| `GET` | `/sessions` | user | list sessions |
| `POST` | `/sessions` | user | create one session |
| `PUT` | `/sessions/{id}` | user | edit a session (moves its folder) |
| `POST` | `/sessions/{id}/close` | user | close the link early |
| `DELETE` | `/sessions/{id}` | user | remove a session |
| `GET` | `/sheets` | user | list linked Google Sheets |
| `POST` | `/sheets` | user | link a sheet |
| `PUT` | `/sheets/{id}` | user | update a linked sheet |
| `DELETE` | `/sheets/{id}` | user | unlink a sheet |
| `POST` | `/sheets/{id}/sync-now` | user | sync one sheet now |
| `POST` | `/admin/settings` | **admin** | save site-server sync settings |
| `POST` | `/admin/sync-now` | **admin** | run the site-server sync now |
| `POST` | `/admin/google-settings` | **admin** | save the Google OAuth client |
| `GET` | `/google/connect` | **admin** | begin the OAuth flow |
| `GET` | `/google/callback` | **admin** | OAuth redirect target |
| `POST` | `/google/disconnect` | **admin** | drop the stored Google token |

`{id}` is constrained to `\d+` in the route table.

### `POST /batch` and `POST /batch/manual`

Both funnel into the same pipeline. `/batch` takes a multipart upload in **`csv_file`**;
`/batch/manual` takes **`rows`**, a JSON-encoded array of
`{theatre, date, startTime, presenterName, presenterEmail}`.

Shared form fields:

| Field | Default | Notes |
|---|---|---|
| `expiry_date` | — | **Required.** `YYYY-MM-DD`, and see the day-granularity rule below. |
| `base_folder` | `File Drops` | Blank falls back to the default. Remembered per user afterwards. |
| `create_users` | off | **Admins/subadmins only** — `403` otherwise. |
| root folder names | — | Collected from the form; created once under `base_folder`. |

**Expiry is validated against a day-granularity rule and today is always rejected.** Nextcloud's
share manager truncates both the expiration date and "now" to midnight before comparing, so any
time of day is ignored and a share effectively expires at **00:00 on the chosen date**. The
earliest valid expiry is therefore **tomorrow**, and the error message says so.

Failure modes: `401` not logged in · `400` no file / unreadable CSV / missing columns / bad
expiry / empty manual rows · `403` non-admin asking for `create_users`.

### Result shape

```json
{ "summary":     { "total": 12, "success": 10, "partial": 1, "error": 1 },
  "rows":        [ { "rowNumber": 2, "date": "...", "theatre": "...", "startTime": "...",
                     "presenterName": "...", "presenterEmail": "...",
                     "status": "success", "message": "",
                     "folderPath": "...", "shareLink": "...", "shareId": "...",
                     "emailSent": true } ],
  "csv":         "…the input CSV with a File Drop Link column…",
  "userSummary": { "total": 3, "created": 2, "existing": 1, "error": 0 },
  "users":       [ { "theatre": "...", "username": "...", "password": "...",
                     "status": "created", "message": "" } ],
  "usersCsv":    "…only when create_users was set…" }
```

**The three row statuses mean genuinely different things**, and the difference matters
operationally:

| Status | Folder | Link | Email | What to do |
|---|---|---|---|---|
| `success` | ✅ | ✅ | ✅ sent | nothing |
| `partial` | ✅ | ✅ | ❌ **not sent** | **Deliver the link by hand.** The link is live and usable. |
| `error` | ✗ or ✅ | ✗ | ✗ | Fix the row and re-run. |

`partial` has two distinct causes, distinguished only by `message`: the email address failed
`FILTER_VALIDATE_EMAIL`, or the mailer threw. Either way the presenter has no link yet.

An `error` row can still have created a folder — folder creation succeeding but *share* creation
failing yields `error` with `message` beginning "Folder created, but the file drop link could
not be created". Re-running will then create a *second* folder (see §2's uniqueness note).

**Only `success` and `partial` rows are persisted** to the sessions table and to the batch
record. `error` rows leave no trace beyond the response.

`users[].password` is **empty for an account that already existed** — the app never reads back
an existing password. A blank password column in the users CSV means "this account was already
there", not "no password was set".

---

## 2. The session CSV contract

> **The same session CSV drives this app and
> [resolve-configurator](https://github.com/allansargeant/resolve-configurator)**, which
> scaffolds the DaVinci Resolve project for the same event. **If you change what a column means,
> check that repo too** — neither repo's tests would catch a divergence on their own.

### Required columns

Five, matched **case-insensitively** on the trimmed header name. Order doesn't matter, extra
columns are ignored, and a UTF-8 BOM on the first header is stripped.

| Canonical | Reported as, when missing |
|---|---|
| `date` | `Date` |
| `theatre` | `Theatre` |
| `start time` | `Start Time` |
| `presenter name` | `presenter name` |
| `presenter email` | `presenter email` |

Any missing column aborts the whole file with a `400` naming every one that's absent — nothing
is created.

Blank lines are skipped, including the three shapes they actually arrive in: `[]`, `[null]` and
`['']` (a trailing newline in an exported CSV gives `[null]`; the Google Sheets API omits
trailing empty cells entirely, so a blank row arrives as `[]`). Every value is `trim()`ed.

Rows carry a `_rowNumber`, 1-based **counting the header as row 1**, so the first data row is
row 2. Manual entry fabricates the same numbering for display parity.

### Accepted date and time formats

Tried in order, first match wins:

```
dates:  Y-m-d   d/m/Y   m/d/Y   d-m-Y   d M Y   j M Y      then strtotime() as a fallback
times:  H:i     H:i:s   g:ia    g:i a   g:i A   g.ia
```

> **⚠ `d/m/Y` is tried before `m/d/Y`.** An ambiguous date like `03/04/2026` is read as
> **3 April**, not 4 March. There is no setting for this. A US-format sheet will silently
> produce sessions on the wrong day whenever the day is ≤ 12.

The `strtotime()` fallback makes date parsing very permissive — it will accept things a
spreadsheet never meant, and a value that parses is never questioned. There is **no time-zone
handling**: dates and times are used as written.

Note that the parsed date is used only for **validation**; the folder is named from the
**original string**, so `2026-08-01` and `1 Aug 2026` produce two different folders.

### Output CSV

The input rows with a **`File Drop Link`** column added. The users CSV (only when
`create_users` was set) carries theatre, username, password and status.

### Folder layout produced

```
<base folder>/<Theatre>/<Date>/<Start Time> - <Presenter Name>
<base folder>/<root folder name>          (one per configured root folder)
```

Every segment goes through `PathSanitizer::sanitizeSegment()`: the characters `/ \ : * ? " < > |`
are **replaced with `-`, not stripped** (so `10:00` and `1000` don't collapse into one folder),
whitespace is collapsed, leading/trailing dots and whitespace are trimmed, empty becomes
`untitled`, and the result is cut to 200 characters.

The leaf folder is created via `createUniqueLeaf`, which appends ` (2)`, ` (3)` … up to **50**
if the name is taken (and retries once on a lost race with a concurrent request). So **a
repeated run does not reuse an existing session folder** — it creates `10:00 - Jane Smith (2)`
alongside the original. Re-running a batch produces duplicates, not idempotence. The
theatre-account and root-folder paths *are* idempotent; only the session leaf isn't.

### Share type

`TYPE_LINK` with **`PERMISSION_CREATE` only, no READ** — Nextcloud's "File drop (upload only)"
link. Anyone with the URL can upload; nobody can list or download what's there.

---

## 3. Admin configuration keys

App config (`getAppValue`/`setAppValue` under app id `filedropbatch`), set from
Settings → Administration → File Drop Batch.

### Site-server sync (rclone over WebDAV)

| Key | Notes |
|---|---|
| remote URL | trailing `/` stripped |
| remote user | on the *destination* instance |
| remote password | **encrypted at rest** via Nextcloud's crypto. Use an **app password** from the remote instance, never a real account password. |
| remote base path | trimmed of `/` |
| rclone binary | defaults to `rclone` on `$PATH` |
| local WebDAV base URL | see below |
| sync enabled | `'1'` / `'0'` |
| last sync at / status / message | written by the job |

**The source leg never stores a password.** Each sync mints a fresh, short-lived Nextcloud app
token for the batch's owner, uses it for the local WebDAV connection, and **revokes it in a
`finally` block** — success or failure.

> **⚠ The transfer is `rclone sync`, not `copy`.** `sync` makes the destination *match* the
> source: **files present at the destination and absent from the source are deleted.** It runs
> with `--create-empty-src-dirs`. If anyone edits or adds files on the site server between syncs,
> the next sync removes them. Treat the site server as a read-only mirror.

**Set the local WebDAV base URL explicitly** behind Docker or a reverse proxy. Auto-detection
uses Nextcloud's URL generator in a cron context, which in a typical `docker-compose` setup
resolves to the *host-mapped* address (`localhost:8080`) rather than the address reachable from
*inside* the container where rclone actually runs (`http://localhost`, Apache on port 80). A
source-leg connection error with a reachable destination is almost always this.

### Google Sheets

Needs a Google Cloud project with the Sheets API enabled and an OAuth 2.0 client ID/secret,
entered in admin settings, then the `/google/connect` flow. See the README.

---

## 4. Database tables

| Table | Holds |
|---|---|
| `fdb_batches` | owner, base folder, expiry, sync state — drives the expiry-triggered sync |
| `fdb_sessions` | one row per created session: theatre, date, start time, presenter, folder path, share id, status, `emailSent` |
| `fdb_sheets` | linked Google Sheets and their sync state |

Sessions carry a status — `open` or `closed` — and cache the display fields, so the sessions
list doesn't have to re-walk the filesystem.

**Editing a session moves its folder and does not touch the share or re-email anyone.**
Nextcloud shares reference a node by **file id, not path**, so the link keeps working across the
move — and a metadata correction shouldn't spam the presenter. If you need the presenter to get a
new link, that's a new session.

Migrations are versioned and dated (`Version010400Date20260718000000.php`). **Add a new migration
rather than editing an applied one** — an edited migration silently skips on any instance that
already ran it.

---

## 5. Background jobs

Both run on **Nextcloud's own cron**, so no extra system cron entry is needed — and neither will
show up in a plain request/response test.

**`SyncExpiredBatchesJob`** — roughly every 15 minutes, finds batches whose expiry has passed and
which haven't synced, and runs **one whole-folder sync per distinct (user, base folder) pair** —
not one per batch, since `rclone sync` only transfers deltas anyway. A failed sync is logged and
**left for retry on the next run** rather than marked done.

**`SyncGoogleSheetsJob`** — re-reads linked sheets through `CsvReader::parseRows()`, so a sheet
must satisfy exactly the same header contract as a CSV. **A row disappearing from the sheet
closes its session** — that is the designed behaviour, and it means deleting a row is a
destructive act with a live consequence.

Both sheet sync and batch processing call the same
`BatchProcessorService::ensureRootFoldersAndTheatreUsers()`, so the "create an account per
theatre" setting behaves identically down both paths.

---

## See also

- [USER-GUIDE.md](USER-GUIDE.md) — running a show with it
- [DEVELOPING.md](DEVELOPING.md) — dev stack, tests, and the security rules
- [README](../README.md) — install, screenshots, site-server sync setup
