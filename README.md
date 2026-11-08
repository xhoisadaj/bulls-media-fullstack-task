# Sheets Sync

Sheets Sync reads Google Sheets that share a similar structure, maps their columns to MySQL tables, and syncs them every day. Every change it finds (new, modified, removed and restored rows, new or missing columns) is shown in a review inbox. Safe changes can be applied automatically, and nothing destructive happens without approval.

Built with **Laravel 13, MySQL 8, Filament 5** and the official **Google API client**.

- [Quick start with Docker](#quick-start-with-docker)
- [Setup on Ubuntu with `setup.sh`](#setup-on-ubuntu)
- [Google credentials](docs/GOOGLE_API.md)
- [How it works](#how-it-works)
- [Requirements → implementation](#requirements--implementation)
- [Using the app](#using-the-app)
- [Demo scenarios](#demo-scenarios)
- [Edge cases](#edge-cases)
- [Commands](#commands)
- [Tests](#tests)
- [Design decisions](docs/DECISIONS.md) and the full [plan](docs/PLAN.md)

---

## Quick start with Docker

The app runs in an **Ubuntu 24.04** container (PHP 8.4, nginx, queue worker and scheduler under supervisor) next to MySQL 8.4. Nothing else needs to be installed on your machine.

```bash
git clone <repository-url> sheets-sync && cd sheets-sync
docker compose up -d --build          # first start installs dependencies and runs migrations (~1–2 min)
docker compose exec app php artisan sheets:demo    # optional: load demo data, no Google account needed
```

Open **http://localhost:8000/admin** and log in with **admin@example.com / password**.

To look at the database, open phpMyAdmin at **http://localhost:8080**. It logs in automatically as the app's database user and only listens on your own machine.

Useful commands:

```bash
docker compose logs -f app                       # app, queue worker and scheduler logs
docker compose exec app php artisan sheets:sync --now
docker compose exec app php artisan test
docker compose down                              # add -v to also delete the database
```

## Setup on Ubuntu

On a fresh Ubuntu 22.04 or 24.04 server or VM, from the repository root:

```bash
sudo ./setup.sh            # or: sudo ./setup.sh --demo   to also load the demo data
```

The script:
- installs PHP 8.4, Composer, MySQL, nginx, supervisor and cron;
- creates the database and a database user with a generated password;
- writes `.env`, generates `APP_KEY`, runs the migrations and creates the admin account;
- configures nginx, a supervisor-managed queue worker and the cron entry for the scheduler.

At the end it prints the URL, the admin login (with a generated password), and the redirect URI to register in Google Cloud. It is safe to run again: the existing database, users, `.env` values and `APP_KEY` are kept.

You can set these variables in front of the command: `APP_URL`, `APP_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `ADMIN_EMAIL`, `ADMIN_PASSWORD`. For example: `sudo APP_URL=https://sync.example.com ./setup.sh`.

## Google credentials

The reviewer uses their **own** Google credentials: no keys, accounts or sheets of mine are included.

- **OAuth (default):** reads the private sheets of the connected Google account. No sheet has to be shared with any email.
- **API key (fallback):** reads sheets set to "Anyone with the link can view". It is used automatically when OAuth isn't connected or can't open a file.

Step-by-step instructions are in **[docs/GOOGLE_API.md](docs/GOOGLE_API.md)**. You enter the values in **Settings → Google connection** (or with `php artisan google:configure`). They are stored **encrypted in the database**, not in `.env`. Only `APP_KEY` and the database login live in `.env`.

---

## How it works

```
FILE (Google spreadsheet)
 └── TAB
      └── SOURCE TABLE      header row + data block (a tab can hold several)
           ├── SOURCE COLUMNS   header, type, "compare" flag
           ├── SOURCE RECORDS   one per sheet row: permanent ID + every value
           └── MAPPINGS         → target table (several allowed)
                └── MAPPING COLUMNS   sheet column → database column (price → product_price)
                                  │
                                  ▼
                   TARGET TABLE (MySQL, created and owned by the sync)
                   rows linked to their source record by _record_id
```

### The sync

One queued job per spreadsheet, scheduled daily at 02:00 (`SYNC_DAILY_AT`) and also available as "Sync now":

1. **Read.** One batch request per file: raw values (numbers, dates) and displayed text (for text columns, so leading zeros survive).
2. **Locate.** Each table is found by its **header names**. Cell positions are only hints, so headers that moved or columns inserted to the left don't matter.
3. **Check columns.** A new column creates an alert only. A missing **mapped** column marks the table as broken and stops it from syncing, without touching the stored data.
4. **Normalize.** `€1,250.50`, `1250.5` and `1.250,50` become the same value, and dates are converted to ISO. Rows with formula errors (`#DIV/0!`) or invalid values (a `TOTAL` row) are skipped and reported with their row number. If a cell shows `Loading...` (IMPORTRANGE), the table is skipped for this run.
5. **Fingerprint.** Each row's tracked values are hashed.
6. **Match.** Rows are compared with the stored records (see below).
7. **Decide.** The sync applies a mass-removal guard and remembers rejected changes, supersedes outdated pending changes, then either queues changes for review or applies them (auto mode).

### Matching rows: identity is proven, never guessed

| Identity mode | How a row is recognised | Detects "modified"? |
|---|---|---|
| **Key columns** | one or more columns declared unique (`order_id`, `date + shop + product`), checked against the data | yes |
| **System ID** | the app writes a `_sync_id` column into the sheet (needs OAuth with write access) | yes |
| **Content** | the row's fingerprint | no: an edit shows as *removed + new*, side by side |

A row's position never counts. Sorting the sheet or moving a row changes nothing. A row that reappears after its removal was approved is reported as **restored**.

### Review and auto mode

| Change | Review mode (default) | Auto mode |
|---|---|---|
| New, modified, restored | waits for approval | applied |
| Removed | waits for approval | **waits for approval** |
| New or missing column | alert, admin decides | alert, admin decides |

When a change is approved, the source record is updated first and then every target table that uses it, in one transaction. Removals are soft deletes (`_removed_at`), so data is never lost. A rejected change isn't raised again unless the row changes again.

### Schema changes (requirement #6)

When a column is added to a mapping and the target table doesn't have it, the sync runs `ALTER TABLE … ADD COLUMN` and **backfills** the existing rows, without creating change entries. The schema manager:
- only touches tables it created (`managed_tables`), never `users` or other app tables;
- only adds columns: it never drops, retypes or renames them;
- validates names (`^[a-z][a-z0-9_]{0,63}$`, no reserved words) and types (from a fixed list);
- runs schema changes before data writes (MySQL commits implicitly on DDL);
- logs every change in `schema_histories`.

Changing where a column is saved is **add + stop**: the new destination is created and filled, and the old column keeps its data.

## Requirements → implementation

| # | Requirement | Where |
|---|---|---|
| 1 | Similar files, extra columns, different positions | Headers matched by name ([`TableLocator`](app/Sync/TableLocator.php), [`StructureInspector`](app/Sync/StructureInspector.php)); tables can start anywhere (e.g. H99) |
| 2 | List of files, saved daily | [`sheets:sync`](app/Console/Commands/SyncSheetsCommand.php) scheduled daily ([`routes/console.php`](routes/console.php)), one [`SyncSpreadsheetJob`](app/Jobs/SyncSpreadsheetJob.php) per file |
| 3 | Configuration of files, tabs, columns | DB tables `spreadsheets`, `tabs`, `source_tables`, `source_columns`, managed in the UI |
| 4–5 | Matching file → table, renamed columns, one file → many tables | `mappings` + `mapping_columns`; [`TargetWriter`](app/Sync/TargetWriter.php) writes each mapping by `_record_id` |
| 6 | New config column → ALTER TABLE + store data | [`SchemaManager`](app/Sync/SchemaManager.php) + [`Backfiller`](app/Sync/Backfiller.php) |
| 7 | Google API token instructions | [docs/GOOGLE_API.md](docs/GOOGLE_API.md) |
| 8 | Repository with setup instructions | this README |
| 9 | Laravel, MySQL | Laravel 13, MySQL 8.4 |
| 10 | Ubuntu setup script | [`setup.sh`](setup.sh) (tested on a clean `ubuntu:24.04` container), plus the Ubuntu-based Docker image |
| 11 | UI to manage everything | Filament admin panel at `/admin` |

## Using the app

1. **Settings → Google connection:** set the OAuth client (or paste its JSON) and click **Connect Google account**, and/or set an API key. ([docs/GOOGLE_API.md](docs/GOOGLE_API.md))
2. **Sources → Spreadsheets:** **Fetch from Google** (OAuth) or **Add by URL**. Then open a spreadsheet and enable its tabs.
3. **Set up a table:** the wizard detects the tables in the tab, suggests column types from the data, and warns about volatile formulas and formula errors. Choose the identity mode (the key is checked for uniqueness) and Review or Auto.
4. **Mappings:** choose an existing target table or create one, then pick the columns and their database names. The plan is validated before any `ALTER TABLE`. **Preview** shows what will be written.
5. **Sync now**, or wait for the daily run. **Review → Review inbox** shows changes with field diffs, which you approve or reject. **Review → Alerts** shows new or missing columns (with "Map this column"), moved headers, blocked mass removals, access problems and fallback use.
6. **Activity:** sync history (counts, skipped rows, the credential used), target tables with a data browser, and schema history.

## Demo scenarios

`php artisan sheets:demo` loads three spreadsheets, stored as JSON in `storage/app/demo`:

| Spreadsheet | Shows |
|---|---|
| **Shop London** | key mode (`order_id`), auto mode, currency-formatted prices, an unmapped `notes` column |
| **Shop Paris** | headers at **H99**, columns in another order, an extra `region` column, review mode |
| **Online Orders** | no ID column (content mode); **one tab → two tables** (`sales` + `customers`); a `#DIV/0!` row and a `TOTAL` row that get skipped; a second **Returns** table stacked below |

London, Paris and Online all write into the same `sales` table, which shows many files → one table.

To simulate edits, then sync and watch the review inbox and alerts:

```bash
php artisan sheets:demo-change edit           # order 1002: €35 → €40 → "modified"
php artisan sheets:demo-change sort           # rows re-sorted → nothing changes
php artisan sheets:demo-change delete         # order 1003 deleted → "removed" waits for approval
php artisan sheets:demo-change restore        # order 1003 back → "restored"
php artisan sheets:demo-change add-column     # new "discount" column → alert only
php artisan sheets:demo-change rename-column  # Paris "price" renamed → table broken, data untouched
php artisan sheets:demo-change clear          # all rows deleted → mass-removal guard
php artisan sheets:demo-change reset          # back to the original demo data
php artisan sheets:sync --now
```

(With Docker, prefix each command with `docker compose exec app`.) Demo mode can be turned off in Settings → Google connection to use real Google Sheets.

## Edge cases

The full list with examples is in [docs/PLAN.md](docs/PLAN.md#12-edge-cases). Most are covered by tests:

- **Sheet structure**
  - Headers not at A1, or moved after rows were inserted
  - Columns reordered; extra columns
  - New column (alert only); mapped column deleted or renamed (table broken, data untouched)
  - Duplicate headers; headers differing only in case or spaces
  - A column with data but no header
  - Stacked or side-by-side tables; identical tables in one tab (closest wins, or alert when ambiguous)
  - Renamed tabs (tracked by tab ID)
- **Values**
  - Formulas (the result is stored); formula errors; volatile `NOW()`/`RAND()` (compare off)
  - `IMPORTRANGE` still loading; `=IMAGE()` URLs
  - Currency, thousand separators and date formats; leading zeros
  - Booleans and checkboxes; text over 255 characters
- **Rows**
  - Moved or re-sorted rows; edits in key mode and in content mode; an edited key
  - Identical rows (matched by count); duplicate or empty keys (flagged)
  - Removals and restores; the sheet cleared by mistake (guard)
  - A row edited again before review (superseded); rejections remembered
- **Operations**
  - One file or table failing doesn't stop the others; two syncs at once (lock)
  - Rate limits (retries with growing delays); a crash halfway (each change is its own transaction, re-running is safe)
  - OAuth expired or revoked (API key fallback + alert); `APP_KEY` changed (asks to re-enter the credentials)

## Commands

| Command | What it does |
|---|---|
| `php artisan sheets:sync [--file=] [--now] [--allow-mass-removal]` | Sync all (or one) spreadsheets: queued, or inline with `--now` |
| `php artisan google:configure [--client-json=path]` | Store the OAuth client and API key, encrypted (hidden input) |
| `php artisan sheets:demo [--fresh]` | Load the demo setup |
| `php artisan sheets:demo-change <scenario>` | Simulate edits in the demo sheets |
| `php artisan sheets:admin <email> [--password=]` | Create the admin account if it doesn't exist |

## Tests

```bash
docker compose exec app php artisan test
```

95 Pest tests run against a separate MySQL test database:
- **unit tests** for the services that don't touch the database or Google: normalizer, locator and detector, fingerprinter, matcher;
- **feature tests** for the whole sync engine, using an in-memory Google gateway: every edge case above, schema safety, backfill, system IDs, the guards;
- **UI tests** that render every admin screen and exercise the main actions (approve, create a mapping, save an encrypted API key with password confirmation, reject a forged OAuth callback).

## Project structure

```
app/Google/            SheetsGateway (real Google + in-memory), credentials (OAuth → API key fallback), importer
app/Sync/              the engine: TableLocator, StructureInspector, ValueNormalizer, Fingerprinter,
                       RecordMatcher, SheetReader, ChangeSetBuilder, ChangeApplier, TargetWriter,
                       SchemaManager, Backfiller, SystemIdWriter, SyncRunner
app/Filament/          admin panel (resources, Google connection page, dashboard widget)
app/Http/Controllers/  GoogleOAuthController (consent redirect + callback)
database/demo/         demo spreadsheets (JSON)
docker/                Ubuntu image, nginx, supervisor, entrypoint
setup.sh               Ubuntu server setup
docs/                  GOOGLE_API.md, DECISIONS.md, PLAN.md
```
