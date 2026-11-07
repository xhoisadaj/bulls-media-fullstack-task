# Design decisions

The main choices, with the alternatives I considered and why I didn't take them.

## 1. Real columns with a safe ALTER TABLE, not JSON or EAV

Requirement #6 asks the sync to add a column to the database table when one is added to the configuration.

- **JSON column** (`extra JSON`): flexible, but hard to query, index and report on.
- **EAV** (`row_id, key, value`): slow, painful to query, and a known anti-pattern for tabular data.
- **Real columns** (chosen): normal SQL, real types, indexes.

Changing the schema at runtime has risks, so the schema manager is strict:
- **add-only:** it never drops, retypes or renames;
- **owned tables only:** `managed_tables`, so `users` can never be altered;
- **validated:** names must match `^[a-z][a-z0-9_]{0,63}$` and aren't reserved words; types come from a fixed list; conflicts with existing column types are rejected;
- **DDL before data:** schema changes run outside data transactions, because MySQL commits implicitly on DDL;
- **logged:** every change goes to `schema_histories`, which replaces the missing migration files.

## 2. A source records layer between the sheet and the target tables

Every sheet row is stored once as a **source record**: a permanent ID, every column's normalized value (JSON), and a fingerprint. Target tables are projections of these records, linked by `_record_id`.

- **Match once, write many.** One decision per row, applied to every table that uses it.
- **Split tables stay linked.** `people_names` and `people_contacts` share `_record_id`.
- **Backfill.** Values of not-yet-mapped columns are already stored, so mapping a column later fills it without guessing.
- **The approved state lives in the database.** The review inbox shows how the sheet differs from it.

## 3. Deterministic row identity, no similarity threshold

I considered similarity matching ("if 75% of the fields are equal, it's the same row") and rejected it:
- the threshold is arbitrary;
- it behaves differently depending on the number of columns;
- it silently guesses identity.

Instead, a row is "the same row" only when it can be **proven**:
- a **key** the admin declares, checked for uniqueness against the data;
- a **system ID** written into the sheet;
- or the **content** itself (fingerprint).

Without an ID, an edited row is reported as **removed + new**, shown side by side, because nothing proves it's the same row. Position is never used: sorting changes nothing.

## 4. Find tables and columns by header name

Positions are hints, not identity. The locator scans the tab for a block that holds at least half of the known headers (so a missing column is reported as "missing", not as "table not found"). It picks the block closest to the saved position, and reports **ambiguous** instead of guessing when two blocks tie. Blocks are split at truly empty columns, so side-by-side tables stay apart, while a column with data but no header is kept inside the block and reported.

## 5. Normalize before comparing

Numbers and dates are read as raw values (Google serial numbers become ISO dates), and text columns as displayed text (so leading zeros survive). Currency signs and thousand separators are removed. Formatting changes are therefore never "changes". Formula errors and invalid values skip only that row, with a report. `Loading...` (IMPORTRANGE) skips the whole table for that run instead of marking every row removed.

## 6. Review by default, auto as an option; removals always reviewed

The task asks for daily automatic saving, and I also wanted nothing risky to happen silently. Each source table has an apply mode:
- **Review** (default): every change waits for approval.
- **Auto:** new, modified and restored rows are applied automatically.

**Removals always wait for a human**, in both modes. Removals are soft deletes. A **mass-removal guard** stops a table when more than half of its rows (and at least 3) would be removed, which catches a cleared sheet or the wrong table being found. Rejected changes are remembered so they aren't raised every day, and a pending change is **superseded** when the row changes again before review.

## 7. OAuth by default, API key as fallback, no service account

- **OAuth:** reads the reviewer's private sheets without sharing them with anyone.
- **API key:** the quickest setup, but public sheets only. It is used automatically, and visibly (history + alert), when OAuth can't open a file. It's never used for rate limits or Google outages.
- **Service account:** left out. It needs every sheet shared with a robot email, which is the step I wanted to avoid. It's the natural production option for fully unattended servers (future work).

## 8. Secrets in the database, encrypted with APP_KEY

The OAuth client secret, API key and refresh token:
- are stored with Laravel's `encrypted` cast;
- are hidden from serialization and logs;
- are write-only in the UI (masked hint only);
- can only be changed after confirming the password, and every change is audited (without the value).

Only `APP_KEY` and the database login stay in `.env`: the key that encrypts the database can't be stored inside it. A leaked database dump alone reveals nothing. If `APP_KEY` changes, the app asks for the credentials to be entered again instead of crashing.

## 9. Small services with one job each

The engine is split so that most of the logic is **pure** (no database, no Google) and can be unit-tested in milliseconds:
- `TableLocator`, `StructureInspector`, `ValueNormalizer`, `Fingerprinter` and `RecordMatcher` are pure;
- `ChangeSetBuilder` (policy), `ChangeApplier` (transactions), `SchemaManager` (DDL), `Backfiller` and `SyncRunner` (orchestration) work with the database.

Google sits behind one interface, `SheetsGateway`. Its in-memory implementation powers both the tests and the demo mode.

## 10. One queued job per spreadsheet

A failing file never stops the others. Within a file, a broken table never stops the other tables (the run is marked `partial`). A cache lock prevents two syncs of the same file at once. Every sync compares the whole sheet with what is stored, so a missed or repeated run is harmless.

## Out of scope (future work)

- Similarity matching (rejected on purpose, see §3)
- Merging duplicates across files into unique entities (e.g. one customer row for many orders)
- Images inserted into cells or floating over the grid (the Sheets API doesn't export them; `=IMAGE()` URLs are supported)
- Multiple users and roles
- Changing the type of, or renaming, an existing target column (remapping is add + stop)
- Service account authentication
