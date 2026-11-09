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
docker compose exec app php artisan sheets:demo    # optional: load demo data (or use Spreadsheets → Demo data)
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

The reviewer uses their **own** Google credentials: no keys, accounts or sheets of mine are included. [docs/GOOGLE_API.md](docs/GOOGLE_API.md) starts with **what the "Google API tokens" are** and then explains how to create each one.

- **OAuth (default):** reads the private sheets of the connected Google account and can list them ("Fetch from Google"). No sheet has to be shared with any email.
- **API key (fallback):** reads sheets set to "Anyone with the link can view", added with **Add by URL** (a key can't list files: it identifies the app, not a person). It is used automatically when OAuth isn't connected or can't open a file.

Step-by-step instructions are in **[docs/GOOGLE_API.md](docs/GOOGLE_API.md)**. You enter the values in **Settings → Google connection** (or with `php artisan google:configure`). They are stored **encrypted in the database**, not in `.env`. Only `APP_KEY` and the database login live in `.env`.

---

## How it works

```
SPREADSHEET (Google file)
 └── TAB
      └── SHEET TABLE        header row + data block (a tab can hold several)        source_tables
           ├── COLUMNS          header, type, "compare" flag                            source_columns
           ├── ROWS             one per sheet row: permanent ID + every value           source_records
           └── COLUMN MAPPING   → database table (several allowed)                       mappings
                └── sheet column → database column (price → product_price)             mapping_columns
                                  │
                                  ▼
                   DATABASE TABLE (MySQL, created and owned by the sync, e.g. sales)    managed_tables
                   our row ID + the mapped columns: id = R-101, R-102, …
```

In the UI these are **Spreadsheets**, **Sheet tables**, **Column mappings** and **Target tables**; the right-hand column shows the database table behind each one.

### The sync

One queued job per spreadsheet, started by the **sync schedules** (Activity → Sync schedules: any times of day, chosen weekdays, a timezone, all files or one file; a default "every day at 02:00, all files" schedule is created on install, `SYNC_DAILY_AT`), and also available as "Sync now" / "Sync all files":

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
| **System ID** (default) | an ID kept in our database only, nothing is written to the sheet. Rows are matched by content; an edited row is paired with its stored record when it stays **in the same place, between the same unchanged rows** | yes, when the row stays in place; always reviewed |
| **Content** | the row's fingerprint only | no: an edit shows as *removed + new*, side by side |

Sorting the sheet or moving an unchanged row changes nothing. In system ID mode, a row that was edited **and** moved can't be proven to be the same row, so it shows as removed + new. Inserting or deleting other rows doesn't break the pairing, because it looks at the unchanged neighbours, not the row number. A position pairing is labelled "Matched by position" in the review inbox and is never auto-applied; rejecting it turns it into removed + new. A row that reappears after its removal was approved is reported as **restored**.

### Review and auto mode

| Change | Review mode (default) | Auto mode |
|---|---|---|
| First sync of a table (every row is new) | **imported right away** | imported right away |
| New, modified, restored | waits for approval | applied |
| Removed | waits for approval | **waits for approval** |
| New or missing column | alert, admin decides | alert, admin decides |

When a change is approved, the source record is updated first and then every target table that uses it, in one transaction. An approved removal deletes the row from the target tables; its last values stay in the source record and the change history, so nothing is lost and a returning row is restored under the same ID. A rejected change isn't raised again unless the row changes again.

### Target tables are minimal

A table like `sales` holds **only our row ID plus the mapped columns**:

```
id    | date       | name   | product_price | amount
R-101 | 2026-08-11 | laptop | 1000.00       | 20
```

`id` is the permanent ID of the sheet row on our side (R-101, R-102, …, unique across all tables, so one sheet row saved into two tables has the same ID in both). Where a row came from, when it was synced and its history live in the app's own tables (`source_records`, `pending_changes`), not in yours.

### Schema changes (requirement #6)

When a column is added to a mapping and the target table doesn't have it, the sync runs `ALTER TABLE … ADD COLUMN` and **backfills** the existing rows, without creating change entries. The schema manager:
- only touches tables it created (`managed_tables`), never `users` or other app tables;
- the sync only adds columns, and never retypes or renames them. An admin can **remove empty columns** and **delete empty tables** under Target tables (one by one or several at once): only when no row holds a value and no mapping uses them, after confirming the password;
- validates names (`^[a-z][a-z0-9_]{0,63}$`, no reserved words) and types (from a fixed list);
- runs schema changes before data writes (MySQL commits implicitly on DDL);
- logs every change in `schema_histories`.

Changing where a column is saved is **add + stop**: the new destination is created and filled, and the old column keeps its data.

## Requirements → implementation

| # | Requirement | Where |
|---|---|---|
| 1 | Similar files, extra columns, different positions | Headers matched by name ([`TableLocator`](app/Sync/TableLocator.php), [`StructureInspector`](app/Sync/StructureInspector.php)); tables can start anywhere (e.g. H99) |
| 2 | List of files, saved daily | [`sheets:run-schedules`](app/Console/Commands/RunSyncSchedulesCommand.php) runs every minute ([`routes/console.php`](routes/console.php)) and starts the due [sync schedules](app/Models/SyncSchedule.php) (daily by default), one [`SyncSpreadsheetJob`](app/Jobs/SyncSpreadsheetJob.php) per file |
| 3 | Configuration of files, tabs, columns | DB tables `spreadsheets`, `tabs`, `source_tables`, `source_columns`, managed in the UI |
| 4–5 | Matching file → table, renamed columns, one file → many tables | `mappings` + `mapping_columns`; [`TargetWriter`](app/Sync/TargetWriter.php) writes each mapping by our row ID (`id` = `R-101`) |
| 6 | New config column → ALTER TABLE + store data | [`SchemaManager`](app/Sync/SchemaManager.php) + [`Backfiller`](app/Sync/Backfiller.php) |
| 7 | Google API token instructions | [docs/GOOGLE_API.md](docs/GOOGLE_API.md): what the tokens are (API key, OAuth access/refresh tokens), then how to create each one, step by step |
| 8 | Repository with setup instructions | this README |
| 9 | Laravel, MySQL | Laravel 13, MySQL 8.4 |
| 10 | Ubuntu setup script | [`setup.sh`](setup.sh) (tested end to end on a clean `ubuntu:24.04` container, including `--demo` and running it a second time), plus the Ubuntu-based Docker image |
| 11 | UI to manage everything | Filament admin panel at `/admin`, with users and roles (admin, editor, viewer) and per-file access |

## Using the app

1. **Settings → Google connection:** set the OAuth client (or paste its JSON) and click **Connect Google account**, and/or set an API key. ([docs/GOOGLE_API.md](docs/GOOGLE_API.md))
2. **Sources → Spreadsheets:** **Fetch from Google** (OAuth) or **Add by URL**. Then open a spreadsheet and enable its tabs.
3. **Add a sheet table** (one wizard, sheet → database): pick the tab; confirm the detected table (a spreadsheet-like preview highlights it; click a cell to choose another header position); choose where to save it (**a new database table** or **an existing one**, e.g. a second file into `sales`); then, per column, the database name and the type (suggested from the data, set once) and whether to save it; finally the identity mode (a key is checked for uniqueness) and Review or Auto. **Create** builds the table and starts the first sync, which imports every row right away.
4. **Column mappings** (optional, advanced): change which columns are saved later, or save one sheet table into **more** database tables (e.g. products into `sales`, customers into `customers`). Names are free text and each column has a type; values are converted when it differs from the sheet type. With an existing table, its columns are suggested, **Map matching columns** pairs same-named columns, and an overview shows what this file fills. **Preview** shows what will be written.
5. **Sync now** / **Sync all files**, or let a **sync schedule** run it (Activity → Sync schedules: several times a day, chosen weekdays, in your timezone; your timezone is set with "Your timezone" and all dates in the app are shown in it). The **first sync of a table imports all its rows right away** (there is nothing to compare with yet); from the next sync on, **Review → Review inbox** shows changes with field diffs, which you approve or reject. **Review → Alerts** shows new or missing columns (with "Map this column"), moved headers, blocked mass removals, access problems and fallback use.
6. **Activity:** sync history (counts, skipped rows, the credential used), target tables with a data browser (filter by the file rows came from), schema history, removal of empty columns and tables, and **Export** of a table's data to **Excel, CSV or JSON** (all rows, or one file's rows via the "From file" filter; also `php artisan sheets:export sales --format=csv`).

### Users and roles

**Settings → Users** (admins): add users with a role and the spreadsheets they can access.

| Role | Can do |
|---|---|
| **Admin** | everything: users, Google connection, sync schedules, all files, changing and deleting database tables |
| **Editor** | on the spreadsheets shared with them: add sheet tables, map columns, sync, approve or reject changes, export |
| **Viewer** | look at the data of the spreadsheets shared with them, and export it |

Everything follows the shared files: other files, their changes, alerts and sync history are not visible, and in a database table shared by several files (e.g. `sales`) a user only sees and exports the rows of their files. Notifications go to admins and to the users a file is shared with. There is one Google connection (set by an admin); see [DECISIONS.md §11](docs/DECISIONS.md) for why users don't connect their own Google accounts.

## Demo scenarios

The demo lets you try everything without a Google account: three example spreadsheets stored as JSON files in `storage/app/demo`. Load or remove it with **Spreadsheets → Demo data → Load demo data / Remove demo data** (admins), or `php artisan sheets:demo` / `php artisan sheets:demo --remove`.

It lives next to your real data without touching it: demo spreadsheets have `demo-…` IDs and are read from the JSON files (every other spreadsheet is read from Google), and their rows go into separate tables, **`demo_sales`**, **`demo_customers`** and **`demo_returns`**. Removing the demo deletes only those spreadsheets and tables.

| Spreadsheet | Shows |
|---|---|
| **Shop London** | key mode (`order_id`), auto mode, currency-formatted prices, an unmapped `notes` column |
| **Shop Paris** | headers at **H99**, columns in another order, an extra `region` column, review mode |
| **Online Orders** | no ID column (content mode); **one tab → two tables** (`demo_sales` + `demo_customers`); a `#DIV/0!` row and a `TOTAL` row that get skipped; a second **Returns** table stacked below |

London, Paris and Online all write into the same `demo_sales` table, which shows many files → one table. The first sync imports every table right away, so the review inbox starts empty: simulate edits to fill it.

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

(With Docker, prefix each command with `docker compose exec app`.)

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
| `php artisan sheets:run-schedules` | Start the syncs whose schedule is due (run every minute by the scheduler) |
| `php artisan sheets:export <table> [--format=xlsx\|csv\|json] [--to=path]` | Export a database table to Excel, CSV or JSON |
| `php artisan google:configure [--client-json=path]` | Store the OAuth client and API key, encrypted (hidden input) |
| `php artisan sheets:demo [--fresh] [--remove]` | Load the demo (into `demo_` tables), start it over, or remove it |
| `php artisan sheets:demo-change <scenario>` | Simulate edits in the demo sheets |
| `php artisan sheets:admin <email> [--password=]` | Create the admin account if it doesn't exist |

## Tests

```bash
docker compose exec app php artisan test
```

152 Pest tests run against a separate MySQL test database (a guard refuses to run them against any database whose name doesn't end in `_test`):
- **unit tests** for the services that don't touch the database or Google: normalizer, locator and detector, fingerprinter, matcher;
- **feature tests** for the whole sync engine, using an in-memory Google gateway: every edge case above, schema safety, backfill, system ID position pairing and rejections, the guards, minimal target tables, schedules (times, weekdays, timezones), exports;
- **transaction tests** that force a failure halfway through a save (a user, a mapping, the wizard, a backfill) and check that nothing partial was saved;
- **UI tests** that render every admin screen and exercise the main actions: the sheet table wizard (new and existing tables), mappings, approving, saving an encrypted API key with password confirmation, rejecting a forged OAuth callback, removing empty columns and tables, loading and removing the demo, and **roles** (what editors and viewers can and can't see or do).

## Project structure

```
app/Google/            SheetsGateway (Google, in-memory, and the router that sends demo-… files to JSON),
                       credentials (OAuth → API key fallback), importer
app/Sync/              the engine: TableLocator, StructureInspector, ValueNormalizer, Fingerprinter,
                       RecordMatcher, SheetReader, ChangeSetBuilder, ChangeApplier, TargetWriter,
                       SchemaManager, Backfiller, SyncRunner
app/Filament/          admin panel (resources, Google connection page, dashboard widget)
app/Exports/           TableExporter (Excel, CSV, JSON)
app/Demo/              DemoData (load / remove the demo, kept apart in demo_ tables)
app/Support/Access.php who can see what (users only see their spreadsheets and their rows)
app/Policies/          permissions per role (admin, editor, viewer)
app/Http/              GoogleOAuthController (consent redirect + callback), user timezone middleware
database/demo/         demo spreadsheets (JSON)
docker/                Ubuntu image, nginx, supervisor, entrypoint
setup.sh               Ubuntu server setup
docs/                  GOOGLE_API.md, DECISIONS.md, PLAN.md
```
