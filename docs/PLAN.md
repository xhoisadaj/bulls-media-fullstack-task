# Final Plan: Google Sheets → MySQL Sync

> **Implementation notes** (where the built app differs from or refines this plan):
> - The `files` table is called `spreadsheets` (model `Spreadsheet`), to avoid clashing with Laravel's `File` facade.
> - Rejection memory for new and modified rows is kept on the rejected pending change itself (its fingerprint), not in a `rejected_fingerprint` column on the record.
> - Text columns are read from the displayed value (this keeps leading zeros); numbers and dates from the raw value. That's two batch reads per file.
> - A table is located when a block of header cells holds at least half of its known headers, so a missing column is reported as "missing", not as "table not found".
> - Mass-removal guard: more than half of the rows **and** at least 3 rows. Override it with "Sync anyway" / `--allow-mass-removal`.
> - System ID mode writes IDs right after the read (while row positions are fresh), not after approval.
> - Headers that appear in the sheet become unmapped source columns automatically, so their values are kept for a later backfill.
> - **Demo mode** (`php artisan sheets:demo`) reads JSON spreadsheets through the same gateway interface, so the app can be explored without a Google account.

## 1. What we're building

A Laravel + MySQL web app where an admin:
1. connects Google Sheets files,
2. defines which tabs, tables and columns to read,
3. maps sheet columns to MySQL tables (one sheet can feed many tables, and many sheets can feed one table),
4. syncs daily or on demand,
5. **sees and controls every change**: new, modified, removed and restored rows, plus new or missing columns. Changes are approved in a review inbox, or applied automatically if the admin chooses.

## 2. Core principles

1. **Find by name, not position.** Columns and tables are located by header names. Cell letters are only hints.
2. **Identity is deterministic, never guessed.** A row is "the same row" only when it can be proven: by fingerprint, by key, or by system ID. No thresholds and no similarity scores.
3. **Match once, write many.** Each sheet row is matched once (as a source record) and written to every target table that uses it.
4. **Compare normalized values, not displayed text.** Formatting never counts as a change.
5. **The user is in control.** No dropped columns, no hard deletes, and nothing uncertain or destructive happens without approval.
6. **Be honest about limits.** What the API or the data can't tell us is reported and documented, never worked around in secret.

## 3. Main concepts

```
FILE (Google spreadsheet)
 └── TAB
      └── SOURCE TABLE (header row + data block; a tab can have several)
           ├── SOURCE COLUMNS (header, type, compare flag)
           ├── SOURCE RECORDS (one per sheet row: our permanent ID + all values)
           └── MAPPINGS (→ target table; several allowed)
                └── MAPPING COLUMNS (source column → target column name)
                                   │
                                   ▼
                        TARGET TABLE (MySQL, managed by us)
                        each row linked by _record_id
```

## 4. Row identity modes (no thresholds)

The admin chooses one mode per source table:

| Mode | How a row is recognised | Detects | When to use |
|---|---|---|---|
| **Key columns** | One or more columns the admin declares unique (`order_id`, or `date+shop+product`). Validated as unique. | new, **modified**, removed, restored, unchanged | the sheet has a real ID |
| **System ID** *(optional)* | The app writes a `_sync_id` column into the sheet and uses it as the key | new, **modified**, removed, restored, unchanged | no natural ID, and the admin accepts that we write to the sheet |
| **Content** | The row's fingerprint is its identity | new, removed, restored, unchanged / moved | no ID, read-only access |

**In content mode, an edited row = old version removed + new version added.** Without an ID, the system **can't prove** they're the same row, so it reports exactly what it sees. The review screen shows both changes from the same sync together.

**Edge cases for system ID mode:**
- Needs **OAuth with write access** (§14). Not available with the API key fallback; while on fallback, new rows without an ID are held until write access is back.
- Rows added by humans have no ID yet → NEW, and the ID is written after they're inserted.
- A copy-pasted row brings its ID along → duplicate ID → both rows flagged and not applied.
- The `_sync_id` column is deleted → structure alert, and the table is marked broken.
- Sorting the sheet keeps each ID with its row, so nothing breaks.

## 5. User flow (screens)

**1. Login.** One admin, created by the setup script.

**1b. Google connection** (settings): connect a Google account with OAuth (default) and/or enter an API key (fallback for public sheets), and see the status of both (§14).

**2. Files**
- "Fetch from Google" lists the spreadsheets of the connected Google account (OAuth only, Drive API). The admin ticks the ones to track.
- "Add by URL" works with both credentials: paste the sheet link, and the file ID is extracted.

**3. File → Tabs:** enable the tabs to use.

**4. Tab → Source tables**
- A grid preview with **auto-detected tables highlighted** ("Table 1: A1:F50, Table 2: H99:M240").
- The admin confirms or adjusts:
  - name
  - header row
  - column range
  - end rule: first empty row (default), end of sheet, fixed row, or before the next table's header

**5. Source table setup**
- Headers with sample values.
- Per column:
  - **type**, auto-suggested: string, text, integer, decimal, date, datetime, boolean, image_url
  - **compare** flag
- **Identity mode** (§4), with a short explanation of what each mode detects.
- **Apply mode:** Review (default) or Auto.
- Warnings: volatile formulas, suspected embedded images, formula errors in the samples.

**6. Mappings** (several per source table)
- Target: an existing managed table or a new one.
- Pick source columns and set target names (`product_name → name`).
- **Validate** (§11), then **Preview**, then **Save**. Saving creates the table or columns, logs the change, and runs the backfill.

**7. Sync**
- "Sync now" (per file or all), plus the daily scheduled run.

**8. Review inbox**
- Pending changes grouped by sync run and source table.
- Tabs: New, Modified, Removed, Restored.
- Modified rows show a field diff (`phone: 0692… → 0699…`).
- Actions: approve all, approve selected, reject.

**9. Structure alerts**
- New column in the sheet → "Add it?" opens the mapping screen pre-filled.
- Mapped column missing, or table not found → table marked broken.
- Header moved → information only.

**10. Notifications:** an in-app bell plus optional email, for pending changes, structure alerts, failed syncs, and an expired or revoked Google connection.

**11. History:** sync runs (status, counts, errors) and schema history.

## 6. Sync engine

One queued job per file, locked so the same file never syncs twice at once:

```
1. READ         one batch API call for all enabled tabs (raw values)
2. LOCATE       find each source table by header names
                moved → continue + info alert · not found/ambiguous → broken, skip
3. STRUCTURE    sheet headers vs source columns
                new header → alert (never auto-added) · mapped header missing → broken, skip
4. EXTRACT      rows within range until end rule; skip empty rows
5. NORMALIZE    by column type
                invalid value (TOTAL row, #REF!, "abc" in decimal) → row skipped + reported
                "Loading..." (IMPORTRANGE) → table skipped this run, DB untouched
6. FINGERPRINT  hash of tracked columns
7. MATCH        step 1 exact fingerprint → step 2 key/system ID (if mode has one)
8. GUARD        removals > 50% of records → stop, alert, queue nothing
9. CHANGE SET   NEW / MODIFIED / REMOVED / RESTORED
10. APPLY       per apply mode (§8)
11. LOG         sync run + notifications
```

## 7. Fingerprints and matching

**Normalization:**

| Type | Rule |
|---|---|
| string / text | trim start and end; empty → null; case matters |
| integer / decimal | fixed precision (`10`, `10.0` → `10.00`) |
| date / datetime | ISO (Google serial numbers converted) |
| boolean | `TRUE/yes/1/x` → 1; `FALSE/no/0/empty` → 0 |
| image_url | URL from `=IMAGE("…")` |

**Tracked columns** = mapped by at least one active mapping **and** compare = on.
**Fingerprint** = hash of the tracked values in a fixed order.
**Hash version** = the set of tracked columns, stored so we know when to recalculate.

**Matching:**
```
SHEET ROWS                                   SOURCE RECORDS
STEP 1  EXACT fingerprint (active + soft-deleted records)
        active       → UNCHANGED (only sheet_row updated)
        soft-deleted → RESTORED
        identical rows matched by count
STEP 2  KEY / SYSTEM ID (only in those modes)
        same key, different fingerprint → MODIFIED
        duplicate key in sheet → flagged, not applied
        empty key → row skipped + reported
LEFTOVERS
        sheet rows   → NEW
        records      → REMOVED
```

**Not a change:**
- the row moved or the sheet was re-sorted
- a formatting change
- a change in an unmapped column
- a change in a compare = off column

## 8. Applying changes

| Change | Review mode | Auto mode |
|---|---|---|
| NEW | pending | applied |
| MODIFIED (proven by key) | pending | applied |
| RESTORED | pending | applied |
| REMOVED | pending | **pending** |
| Structure (new or missing column) | admin decides | **admin decides** |

Removals and structure changes **always** need a human. The demo seed configures one table in auto mode, so the reviewer sees the "daily automatic save" requirement working.

**Applying a change** updates the source record first, then every mapping's target table, in one transaction:

| Change | Source record | Target tables (by `_record_id`) |
|---|---|---|
| NEW | create (new permanent ID) | INSERT |
| MODIFIED | update values + fingerprint | UPDATE (only mappings whose columns changed) |
| REMOVED | set `removed_at` | set `_removed_at` (soft delete) |
| RESTORED | clear `removed_at` | clear `_removed_at` + update |

**Pending-change rules:**
- **The sheet changes again before review:** the old pending change is **superseded**, and the new one is built against the database's current state.
- **Rejected NEW or MODIFIED:** the rejected fingerprint is remembered and not raised again unless the row changes again.
- **Rejected REMOVED:** the record is marked "keep." It isn't raised again until the row reappears in the sheet and then disappears again.
- **The database holds the approved state.** The inbox shows how the sheet differs from it.

## 9. Target tables and schema changes (requirement #6)

**Every target table:**
```
id                 auto-increment
_record_id         link to source record (unique, indexed)
_source_table_id   origin file/tab/table
...mapped columns...
_synced_at
_removed_at
```
- Many sources → one target table (3 shop files → `sales`).
- One source → many target tables (`people_names` + `people_contacts`, linked by `_record_id`).

**Schema manager rules:**
- It only touches **managed tables**, never app tables.
- **Add only.** Nothing is dropped or retyped. Removing a column from a mapping only stops syncing it.
- Names match `^[a-z][a-z0-9_]{0,63}$`, with no reserved words. Types come from a fixed list.
- Type conflicts with an existing target column → validation error.
- Schema changes run before any data transaction (MySQL commits automatically on schema changes).
- Every change is logged to schema history.

**Adding a column to a mapping:**
```
1. ALTER TABLE ... ADD COLUMN ... NULL
2. BACKFILL existing rows (matching rules per identity mode in §10)
3. Recalculate fingerprints (new hash version)
4. No change entries — the admin asked for it
```

## 10. Configuration changes

### 10.1 A new column appears in the sheet

**Phase 1: the column exists in the sheet but isn't mapped (same in all 3 modes)**
- A structure alert appears: *"New column `phone` found. Add it?"*
- The column is **not** part of the fingerprint (only tracked columns are), so every fingerprint stays the same.
- No change entries, and the target tables are untouched.
- Its values are stored in the source records' JSON. Untracked column values are refreshed on every sync, and this never creates change entries.

```
                 KEY COLUMNS     SYSTEM ID      CONTENT
Fingerprint      unchanged       unchanged      unchanged
Change entries   none            none           none
Target tables    untouched       untouched      untouched
Alert            ✔               ✔              ✔
```

**Phase 2: the admin maps it (`phone → phone_number`)**

The admin's decision is the only trigger for a database change (requirement #6):
```
1. ALTER TABLE people ADD COLUMN phone_number ... NULL
2. BACKFILL: read the sheet, match each row to its record, fill the new column
3. The column is now tracked → recalculate all fingerprints (new hash version)
4. No change entries
```

How the backfill matches rows depends on the identity mode:

| | Key columns | System ID | Content |
|---|---|---|---|
| Backfill match | by key | by `_sync_id` | by **old** fingerprint (calculated without the new column) |
| Identity | unchanged | unchanged | fingerprint replaced, record ID kept |
| Rows also edited since the last sync | filled normally | filled normally | no old-fingerprint match → skipped, handled by the normal sync (REMOVED + NEW, pending) |
| Change entries | none | none | none (except the edited rows) |

Content mode example:
```
Sheet row:  Xhoi | Tirana | 069222 | 20
Old fingerprint (without phone):  Xhoi|Tirana|20         → F1 → record R-501 ✔
Fill:                             R-501.phone_number = 069222
New fingerprint (with phone):     Xhoi|Tirana|069222|20  → F8 → saved on R-501
```

### 10.2 The admin changes where a column is saved

Examples: `price → product_price` becomes `price → unit_price` (same table, new name), or `phone` moves from `people_names` to `people_contacts` (different table).

**This is never a rename or a move in the database. It's an add + stop:**
```
1. ADD the new destination (ALTER TABLE ADD COLUMN, or create in the other table)
2. BACKFILL the new destination
3. STOP writing to the old destination
4. The old column and its data STAY (marked "no longer mapped" in the UI)
```

Why: another mapping (from another file) may still write to the old column, nothing is ever lost, and it follows the add-only rule.

**Effect on identity: none, in all 3 modes.** Fingerprints are built from source columns (`price`), not target names (`unit_price`). The tracked set is unchanged, so there's no rehash and no change entries.

| Admin action | What happens |
|---|---|
| Unmap a column completely (no mapping uses it) | tracked set shrinks → fingerprints recalculated from the JSON → no change entries. Content mode: records keep their IDs. |
| Change a column's type | not allowed on an existing target column. Map it to a **new** column with the new type. |
| Remap or unmap a key column | the key is a source table setting, independent of mappings → identity unaffected |
| Change the key columns (`order_id` → `invoice_no`) | the new key is validated as unique, then identity is rebuilt from the JSON. Not unique → rejected. |
| Switch identity mode (e.g. content → key) | validated, then identity is rebuilt from the JSON. Records keep their permanent IDs. |
| Pending changes exist when the configuration changes | **superseded**, and recalculated on the next sync with the new configuration |

### 10.3 A column moves inside the sheet

Nothing happens, in all 3 modes. Columns are found by header name, so position never matters.

### 10.4 Rules

1. Sheet changes never change the database by themselves. A new column only creates an alert.
2. Configuration changes are the admin's decision, so they apply without change entries (ALTER + backfill).
3. Destinations are only added, never renamed or dropped. Old data stays.
4. Fingerprints follow source columns, not target names, so remapping doesn't touch identity.
5. Records keep their permanent IDs through every configuration change. Only the fingerprint is recalculated.
6. Anything that can't be matched with certainty goes through the normal review.

## 11. Validation before saving

- Mapped headers exist in the sheet.
- No two source columns map to the same target column.
- Names are valid and not reserved. The target is managed or new.
- Types fit the real data: failures are shown with row numbers.
- No type conflict with existing target columns.
- Key columns (key mode) are unique and non-empty in the current data.
- In system ID mode, OAuth is connected with write access.
- Warnings: volatile formulas, suspected embedded images, no identity key (content mode can't detect "modified").
- A preview of the rows to be written.

## 12. Edge cases

**Sheet structure**
- Headers not at A1: auto-detected, and found again by name on each sync.
- Columns reordered: matched by name.
- Extra columns: stored and ignored in comparison.
- New column: alert, then the admin decides.
- Mapped column missing: table marked broken, alert.
- Duplicate header names: validation error.
- Header spaces or case: normalized.
- Several tables in one tab (stacked or side by side): defined separately, each with its own end rule.
- Identical headers in two tables of one tab: resolved by closest position. If still ambiguous, alert.
- Table shifted by inserted columns: found again by header names.
- Tab renamed: tracked by tab ID. Tab deleted: alert.
- File unshared or deleted: that job fails cleanly, the others continue.

**Cell values**
- Formulas: the result is stored.
- Formula errors: row skipped and reported.
- Volatile formulas: warning, and the column can be marked compare = off.
- `IMPORTRANGE` loading: table skipped, database untouched.
- Array formulas: read as normal values.
- `=IMAGE()`: URL stored. In-cell or floating images: not available through the API, stored as null, documented.
- Currency, separators, date formats: removed by normalization.
- Empty rows: skipped. Total or summary rows: fail validation and are reported.

**Rows**
- Row moved or sheet re-sorted: unchanged.
- Same name/surname/city with a different phone: two different rows, both stored. Data is never assumed unique.
- Fully identical rows: allowed, matched by count.
- Edit in key or system ID mode: MODIFIED.
- Edit in content mode: REMOVED + NEW, shown together.
- Key value itself edited: REMOVED + NEW.
- Duplicate or empty key: flagged, not applied.
- Deleted row comes back: RESTORED.
- Sheet cleared or filtered by mistake: mass-removal guard.
- Change before review: superseded. Rejected changes: not raised again.

**Operations**
- Rate limits: one batch call per file, plus retries with increasing delays.
- Concurrent syncs: a lock per file.
- Failing file: isolated job.
- Large sheets: chunked processing.
- OAuth token expired or revoked: API key fallback for public sheets, alert "Reconnect Google account" (§14).
- Private sheet with only the API key, or both credentials failing: that file fails with both reasons, the others continue.

## 13. Data model

| Table | Key fields |
|---|---|
| `users` | admin |
| `files` | google_id, name, is_active |
| `tabs` | file_id, google_tab_id, name, is_active |
| `source_tables` | tab_id, name, header_row_hint, column_range_hint, header_names (json), end_rule, identity_mode, key_columns (json), apply_mode, hash_version, status |
| `source_columns` | source_table_id, header, type, compare, is_present |
| `source_records` | source_table_id, data (json, all columns), fingerprint, hash_version, identity_value, sheet_row, rejected_fingerprint, keep_on_removal, removed_at, last_seen_at |
| `mappings` | source_table_id, managed_table_id, is_active |
| `mapping_columns` | mapping_id, source_column_id, target_column |
| `managed_tables` | name, created_by |
| `sync_runs` | file_id, trigger, credential_used (oauth/api_key), status, counts (json), errors (json), started_at, finished_at |
| `pending_changes` | sync_run_id, source_table_id, source_record_id, type, old (json), new (json), sheet_row, status (pending/approved/rejected/superseded), decided_by, decided_at |
| `structure_alerts` | source_table_id, type, details (json), status |
| `schema_history` | managed_table_id, action, column, type, user_id, created_at |
| `notifications` | Laravel default |
| `google_settings` | one row: oauth_client_id, oauth_client_secret (encrypted), api_key (encrypted), refresh_token (encrypted), oauth_email, scopes (json), oauth_status (connected/expired/revoked/not_set), api_key_status (valid/invalid/not_set), connected_at, last_checked_at, last_error, updated_by |
| `credential_audit` | user_id, credential (oauth_client/api_key/oauth_connection), action (set/replaced/removed/connected/disconnected), created_at. Never stores values. |

## 14. Google connection (OAuth default + API key fallback)

The reviewer uses **their own** Google credentials, created by following GOOGLE_API.md. Nothing of ours (keys, accounts, sheets) is shared or committed. Google credentials are entered on the settings screen and **stored encrypted in the database**, not in `.env` (§14.5). **No sheet ever needs to be shared with an email.**

| | **OAuth (default)** | **API key (fallback)** |
|---|---|---|
| How it works | the admin clicks "Connect Google account" and approves Google's consent screen; we store a refresh token | the admin enters a Google Cloud API key |
| What it can read | **private** sheets the connected user can open | **public** sheets only ("Anyone with the link can view") |
| List files ("Fetch from Google") | ✔ (Drive API) | ✗ (add files by URL) |
| Write (system ID mode) | ✔ (write scope, requested only when needed) | ✗ |
| Can it fail by itself? | yes: refresh token can expire or be revoked | rarely: only if the key is deleted or restricted |
| Reviewer effort | ~10 minutes (OAuth client + consent screen) | ~2 minutes |

Why an API key can't read private sheets: a key identifies the Google Cloud *project*, not a person. For a private sheet, Google must know *who* is asking, which is what OAuth provides.

### 14.1 How the fallback works

For every file, `GoogleCredentialProvider` tries the credentials in a fixed order:

```
1. OAuth (default)  ── connected and healthy? ── works → use it
        │ fails: not connected, token expired/revoked,
        │        or "no access" (403/404) to this file
        ▼
2. API key (fallback) ── works (sheet is public) → use it
        │ fails
        ▼
   file fails with both reasons:
   "OAuth: token expired · API key: sheet is not public"
```

- The fallback is tried **only for authentication and access errors**. Rate limits, Google server errors and invalid data are never retried with the other credential.
- Each sync run records which credential was used (`credential_used`), so History shows "File A synced via fallback".
- **No silent switching:** the first time the fallback is used, the admin is notified ("Running on fallback, reconnect Google account"), and the reminder repeats daily while it continues.

| Situation | What happens |
|---|---|
| OAuth never connected (only the API key is set) | the API key is used for everything. Enough for the public demo sheets. |
| OAuth token expired or revoked | the API key takes over for public sheets, plus a reconnect alert |
| OAuth works but the user has no access to a file | the API key is tried in case the sheet is public, otherwise "not accessible" |
| Both fail | that file fails with both reasons. Other files continue. |

**Limits while running on the fallback:**
- **File listing** is unavailable. "Add by URL" still works.
- **Private sheets** can't be read: those files fail with "Reconnect Google account, or make the sheet public."
- **System ID mode** can't write IDs. Existing rows still sync. New rows without an ID are **held** (not inserted), with the alert "waiting for write access", until OAuth is back. Otherwise we'd insert rows that can't be tracked later.

### 14.2 Settings screen: "Google connection"

```
Google connection
──────────────────────────────────────────────
OAuth client
  Client ID       [ 1234-abc.apps.googleusercontent.com ]
  Client secret   [ ••••••••••••a9F3 ]  (Replace)
  Redirect URI    http://localhost:8000/google/callback  [Copy]
                  ↑ paste this into Google Cloud → Clients
  [ Connect Google account ]   Status: not connected

API key (fallback)
  Key             [ ••••••••••••Qx7 ]   (Replace)  [Test key]
                  Status: valid

Currently using: API key (fallback)
```

- **OAuth:** client ID, client secret, "Connect Google account" / "Reconnect", connected email, status `connected` · `expired` · `revoked` · `not set`. "Connect" stays disabled until a client ID and secret are saved.
- **Redirect URI:** generated from the app's URL and shown with a copy button, so it always matches what's registered in Google Cloud.
- **API key:** input field, "Test key" button (one small request to the Sheets API), status `valid` · `invalid` · `not set`.
- A line saying which one is currently in use: "Using OAuth" or "Using API key (fallback)".
- Secret fields are **write-only**: after saving, only a masked hint is shown. Changing or removing a credential asks for the admin's password again.

### 14.3 OAuth flow

One-time setup in Google Cloud (GOOGLE_API.md): create a project, enable the Sheets and Drive APIs, configure the consent screen and add the admin as a test user, create a "Web application" OAuth client, register the redirect URI (copied from the settings screen), and paste the client ID and secret into Settings → Google connection.

```
1. Admin clicks "Connect Google account"
2. App redirects to Google with: client ID, scopes, redirect URI, access_type=offline, prompt=consent, state
3. Admin signs in and clicks "Allow"
4. Google redirects to /google/callback?code=...&state=...
5. App checks that state matches (CSRF protection)
6. App exchanges the code + client secret (server to server) for an access token (~1h) + refresh token
7. App stores the refresh token encrypted; status = connected
Every night: refresh token → new access token → read sheets. If the refresh fails → expired/revoked → fallback + alert.
```

### 14.4 Edge cases

1. **Testing mode expiry.** While the Google OAuth app is in "Testing" status, refresh tokens expire after about 7 days (verify when building). The sync detects it → `expired` → API key fallback + "Reconnect" alert.
2. **Offline access.** We always request `access_type=offline` and `prompt=consent`, otherwise there's no refresh token for the nightly sync. If one is still missing, connecting fails with an explanation.
3. **Access revoked or password changed.** Refresh fails → `revoked` → fallback + alert.
4. **Scopes.** Read-only by default (`spreadsheets.readonly` + Drive read access to list files). The write scope is requested only when system ID mode is enabled (incremental authorization). Drive scopes are sensitive or restricted for Google, which is fine for a test app with test users. Check the current rules when building.
5. **Tampered callback.** `state` doesn't match → callback rejected.
6. **Redirect URI.** Must exactly match the one registered (e.g. `http://localhost:8000/google/callback`). The settings screen shows it with a copy button, and the setup script prints it. If the app URL changes (e.g. port 8000 → 8080), the shown URI updates and a warning says "Update the redirect URI in Google Cloud".
7. **Private sheet with the API key only.** "This sheet isn't public. Make it 'Anyone with the link can view', or connect Google with OAuth."
8. **API key invalid or restricted to the wrong API.** "Test key" and the sync report a clear error (e.g. "This key is not allowed to use the Google Sheets API").
9. **System ID mode with only an API key.** The mode can't be enabled: "System ID mode needs OAuth with write access."
10. **Storage.** Client secret, API key and refresh token are encrypted in the database, never shown in the UI or logs (§14.5).
11. **Credential added or removed.** All tracked files are re-checked for access immediately, and inaccessible ones are flagged.
12. **Wrong client secret.** "Connect" fails at the code-exchange step; Google's message ("invalid client") is shown on the settings page.
13. **Client secret replaced while connected.** The existing refresh token belongs to the old client, so the admin is asked to reconnect.

### 14.5 Storing credentials securely in the database

**What stays in `.env`:** only `APP_KEY` (the encryption key) and the database credentials. `APP_KEY` can't go in the database, because it's the key that encrypts everything there. With this split, a copy of the database alone (a leaked backup, a SQL dump) shows only scrambled text. `setup.sh` generates `APP_KEY` automatically. **Nothing Google-related is written to `.env`**, and `.env.example` has no Google variables.

**What goes in the database (`google_settings`, one row):** OAuth client ID, client secret, API key, refresh token, connected email, scopes, statuses.

**How it's protected:**
1. **Encrypted columns:** Laravel's `encrypted` cast (AES-256 with `APP_KEY`, plus a signature so a tampered value is rejected). Values are decrypted only at the moment the app calls Google, and the decrypted value is never cached.
2. **Never shown back:** secret fields are write-only in the UI (masked hint only), marked hidden on the model so they never appear in JSON, and excluded from logs and error reports.
3. **Confirm password to change:** replacing or removing a credential requires the admin's password again.
4. **Audit trail:** who changed which credential and when, never the value ("Admin replaced the API key, 2026-11-07 14:02").

**Terminal option:** `setup.sh` can ask for the values, or `php artisan google:configure` can be run anytime. Input is hidden while typing, and values go straight into the database, encrypted.

**Edge cases:**

| Case | What happens |
|---|---|
| `APP_KEY` lost or changed | secrets can't be decrypted → status "Credentials can't be read, please re-enter them". Nothing crashes, sync data is unaffected. |
| `APP_KEY` rotated on purpose | the old key is kept as a "previous key" during rotation (Laravel 11+), and a command re-encrypts the secrets with the new key |
| Database restored on another server | without the original `APP_KEY` → same "re-enter" message. Documented: back up `APP_KEY` separately from the database. |

## 15. Tech stack

| Area | Choice |
|---|---|
| Backend | Laravel (latest), MySQL 8 |
| Google | `google/apiclient`: Sheets API + Drive API |
| Auth | OAuth 2.0 user login (default) with API key fallback for public sheets, behind `GoogleCredentialProvider` (§14) |
| UI | Filament for standard screens, plus custom Livewire pages: table detection grid, mapping wizard, review inbox with diffs |
| Jobs | Database queue (one job per file) + scheduler `dailyAt('02:00')` |
| Notifications | Laravel (database + mail) |
| Tests | Pest, with a fake Google client |

Services are described in §16.

## 16. Sync engine services

The engine is a chain of small services, each doing one job, orchestrated by `SyncFileJob`.

```
                         SyncFileJob  (the orchestrator, one per file)
                              │
     ┌────────────────────────┼─────────────────────────────────────────┐
     ▼                        ▼                                         ▼
GoogleSheetsClient   for each source table:                     after everything:
 (read the file)      TableLocator      → where is the table?    notifications
                      StructureInspector→ did columns change?     finish sync_run
                      ValueNormalizer   → clean the values
                      Fingerprinter     → hash each row
                      RecordMatcher     → compare with DB
                      ChangeSetBuilder  → decide what each change is + save it
                      ChangeApplier     → write approved/auto changes
                      SystemIdWriter    → (system ID mode) write IDs to sheet

Called outside the daily sync (when the admin changes configuration):
                      SchemaManager     → create table / add column safely
                      Backfiller        → fill new columns for existing rows
                      ChangeApplier     → also runs when the admin clicks "Approve"
```

**Why split it like this:**
- **Each piece can be tested alone.** Services marked **[pure]** touch neither the database nor Google: data in, data out.
- **Each edge case lives in exactly one place.**
- **Google can be faked.** Tests never call the real API.

### 16.1 `GoogleCredentialProvider`: gives the client valid credentials

- Two implementations: `OAuthCredentials` (refresh token → access token, refreshed automatically) and `ApiKeyCredentials` (public sheets only).
- Tries them in order, OAuth first, and returns which one succeeded (stored as `credential_used`).
- Falls back only on authentication or access errors (not connected, expired, revoked, 403/404), never on rate limits or server errors.
- On an expired or revoked token, it updates the `google_settings` status and notifies the admin (§14.1).

### 16.2 `GoogleSheetsClient`: talks to Google

The **only** place that calls the Google API. Everything else receives plain arrays.

- `listSpreadsheets()`: files of the connected Google account (Drive API, OAuth only)
- `getTabs(file)`: tab names and tab IDs
- `readTabs(file, tabs)`: **one batch call** for all enabled tabs, raw values (`UNFORMATTED_VALUE`)
- `readFormulas(file, tab, rows)`: formulas (`FORMULA` mode), used at setup to detect volatile functions and extract `=IMAGE()` URLs
- `writeColumn(file, tab, column, values)`: only used by `SystemIdWriter`

It's an interface with a **real** implementation (google/apiclient + retries with increasing delays on rate limits) and a **fake** one (prepared arrays, for tests and demos).

Errors are turned into clear messages: file not accessible, sheet not public (API key), credentials invalid, rate limit (retry, then fail). On an access error it asks `GoogleCredentialProvider` for the next credential and retries once.

### 16.3 `TableLocator` [pure]: finds the table inside the tab

- **Input:** tab grid + `header_names`, `header_row_hint`, `column_range_hint`, `end_rule`
- **Output:** header row, column position of each header, data rows with their sheet row numbers

How it works:
1. Check the hint position first (fast path).
2. If the headers aren't there, scan the whole tab for a row containing them.
3. Several matches → pick the one closest to the hint. Equally close → `ambiguous`.
4. Map each header to its column.
5. Read rows until the end rule stops. Skip fully empty rows.

```
found: yes, header_row: 99 (moved: no)
columns: {order_id: H, product_name: I, price: J, amount: K, notes: L}
rows: [ {sheet_row:100, values:{...}}, {sheet_row:101, values:{...}} ]
```

Owns: moved or shifted headers, not found, ambiguous tables, end of data, empty rows.

### 16.4 `StructureInspector` [pure]: compares the sheet's columns with the configuration

- **Input:** headers found + configured source columns + mappings
- **Output:** structure findings

Rules:
- New header → `column_added` (alert, never auto-mapped)
- Missing **mapped** header → `column_missing`, table must not sync (broken)
- Missing **unmapped** header → `is_present = false`, sync continues
- Comparison ignores case and extra spaces
- Duplicate header names → error, table broken

### 16.5 `ValueNormalizer` [pure]: cleans every value by its type

- **Input:** one row's raw values + column types
- **Output:** normalized values, or a list of problems for the row

```
price   (decimal):   50          → 50.00
price   (decimal):   "€1,250.5"  → 1250.50
date    (date):      45967       → 2025-11-06   (Google serial number)
name    (string):    "  Bag "    → "Bag"
active  (boolean):   "yes"       → 1
notes   (string):    ""          → null
photo   (image_url): =IMAGE("https://x.com/a.png") → "https://x.com/a.png"
```

Problems (with row number and column): invalid value, formula error (`#REF!`, `#N/A`, `#DIV/0!`), and `"Loading..."` (temporary: the whole table is skipped this run). A row with a problem in a tracked column is skipped and reported.

### 16.6 `Fingerprinter` [pure]: hashes each row

- **Input:** normalized values + tracked columns
- **Output:** fingerprint + hash version

```
tracked columns, fixed order:  amount, order_id, price, product_name
values:                        2 | 1001 | 50.00 | Shoes
hash:                          a1f3
```

- Fixed order, so the same values always give the same hash.
- Only tracked columns are included.
- Values are encoded safely (a JSON array is hashed, not a joined string), so a value containing a separator can't make two rows look the same.
- Can also compute an **old-version** fingerprint (used by the backfill in content mode).

### 16.7 `RecordMatcher` [pure]: compares the sheet with what we stored

- **Input:** today's rows (fingerprints + key values) + stored records + identity mode
- **Output:** a match result (facts only, no decisions)

```
STEP 1  EXACT fingerprint
        active record     → UNCHANGED
        soft-deleted one  → RESTORED
        identical rows matched by count
STEP 2  KEY / SYSTEM ID (only in those modes)
        same key, different fingerprint → MODIFIED (with field diff)
        duplicate key in sheet          → FLAGGED
        empty key                       → SKIPPED
LEFTOVERS
        sheet rows → NEW
        records    → REMOVED
```

The most heavily tested service: one test per edge case (moved rows, re-sorted sheet, duplicates, edited key, restore, each identity mode).

### 16.8 `ChangeSetBuilder`: applies the business rules and saves pending changes

- **Input:** match result + source table settings + existing pending changes
- **Output:** rows in `pending_changes`, plus the changes to apply immediately

Rules, in order:
1. **Mass-removal guard:** more than 50% REMOVED → stop, save nothing, alert.
2. **Rejected memory:** ignore a NEW or MODIFIED whose fingerprint equals `rejected_fingerprint`, and a REMOVED on a record with `keep_on_removal`.
3. **Supersede:** an older pending change for the same record → `superseded`.
4. **Apply mode:** auto → NEW, MODIFIED and RESTORED created as `approved` and passed to `ChangeApplier`. Review, or any REMOVED → `pending`.
5. **Unchanged rows:** no change entry. Only `sheet_row`, `last_seen_at` and untracked JSON values are updated.

`RecordMatcher` answers "what's different?" (facts). `ChangeSetBuilder` answers "what do we do about it?" (policy).

### 16.9 `ChangeApplier`: writes changes to the database

Applies **one approved change** to the source record and every mapping's target table, in one transaction. Called by the sync (auto mode) and by the review inbox ("Approve").

```
NEW       → create source_record → INSERT into every mapping's target table
MODIFIED  → update source_record → UPDATE target rows WHERE _record_id = X
            (only mappings whose columns changed)
REMOVED   → removed_at / _removed_at = now
RESTORED  → clear removed_at / _removed_at, update values
```

- All or nothing: any failure rolls back the whole change.
- Re-checks that the change is still current (not superseded since the page was opened).
- Never changes the schema.

### 16.10 `SchemaManager`: changes database structure safely

The **only** place that runs `CREATE TABLE` or `ALTER TABLE`. Called when a mapping that needs a new table or column is saved.

```
1. Validate names   ^[a-z][a-z0-9_]{0,63}$, not reserved
2. Validate target  in managed_tables (or being created now)
3. Validate types   fixed list, no conflict with an existing column
4. Compare          read the real table structure from MySQL
5. Create/add       CREATE TABLE (with system columns) or ADD COLUMN ... NULL
6. Register         managed_tables + schema_history
```

Never: drops, type changes, renames, non-managed tables, or running inside a data transaction.

### 16.11 `Backfiller`: updates existing data after configuration changes

Called when a column is newly mapped or remapped, the tracked columns change, or the key or identity mode changes. It never creates change entries.

- **New column:** read the sheet, match rows (by key, by `_sync_id`, or by old fingerprint in content mode), fill the column, recalculate fingerprints. Unmatched (edited) rows are left for the normal sync.
- **Remap:** fill the new destination; the old destination keeps its data and stops receiving updates.
- **Rehash or identity rebuild:** recalculated from the stored JSON, no sheet read.
- Open pending changes for the source table are marked superseded.

### 16.12 `SystemIdWriter`: writes IDs into the sheet (system ID mode only)

1. First time: add a `_sync_id` header at the end of the table.
2. For rows with an empty `_sync_id` (after they're inserted): generate an ID and write it.
3. Writes are batched into one API call.

Owns: duplicate IDs (flagged, never overwritten silently), deleted `_sync_id` column (alert, broken), missing write permission (the mode can't be enabled), running on the API key fallback (new rows held until OAuth write access is back). The only service that changes the user's sheet.

### 16.13 `SyncFileJob`: the orchestrator

```
SyncFileJob(file)
 1. lock the file (skip if already syncing)
 2. create sync_run (status: running)
 3. GoogleSheetsClient.readTabs(file)          ← one API call
 4. for each active source table:
      a. TableLocator        → not found/ambiguous? alert, broken, next table
      b. StructureInspector  → save alerts; mapped column missing? broken, next table
      c. ValueNormalizer     → "Loading..."? skip table; invalid rows → report
      d. Fingerprinter
      e. RecordMatcher
      f. ChangeSetBuilder
      g. ChangeApplier       → auto-approved changes
      h. SystemIdWriter      → system ID mode, new rows inserted
 5. send notifications
 6. finish sync_run (status, counts, errors), release lock
```

- One table failing doesn't stop the others (the run ends as `partial`).
- One file failing doesn't stop other files (separate jobs).
- The daily scheduler and "Sync now" dispatch the same job.

### 16.14 Summary

| Service | Question it answers | Pure? | Edge cases it owns |
|---|---|---|---|
| `GoogleCredentialProvider` | Are we allowed in? | no | OAuth → API key fallback, expired or revoked tokens, invalid keys |
| `GoogleSheetsClient` | What's in the file? | no (API) | access errors, rate limits |
| `TableLocator` | Where is the table? | ✔ | moved/shifted headers, ambiguous tables, end of data |
| `StructureInspector` | Did the columns change? | ✔ | new or missing columns, duplicate headers |
| `ValueNormalizer` | What is the real value? | ✔ | formats, formula errors, invalid values, loading |
| `Fingerprinter` | What is this row's fingerprint? | ✔ | column order, safe encoding, hash versions |
| `RecordMatcher` | Which row is which? | ✔ | moved rows, duplicates, keys, restore |
| `ChangeSetBuilder` | What do we do about it? | no | mass removal, rejected memory, supersede, auto vs review |
| `ChangeApplier` | Write it | no | transactions, stale changes |
| `SchemaManager` | Change the structure safely | no | names, types, managed-only, add-only |
| `Backfiller` | Update data after configuration changes | no | identity per mode, rehash |
| `SystemIdWriter` | Label rows in the sheet | no (API) | duplicate or deleted IDs, permissions |
| `SyncFileJob` | Run it all in order | no | locks, isolation, logging |

## 17. Deliverables

1. **Git repository.**
2. **README:** features, quick start, how sync works (diagrams), edge cases, limitations.
3. **docs/GOOGLE_API.md:** step by step with screenshots, in two sections:
   - **Option A: OAuth (recommended, private sheets):** create a Cloud project, enable the Sheets and Drive APIs, configure the consent screen, add test users, create a "Web application" OAuth client, register the redirect URI, paste the client ID and secret into Settings → Google connection, click "Connect Google account". Includes the note about refresh tokens expiring in Testing mode.
   - **Option B: API key (fallback, ~2 minutes, public sheets only):** enable the Sheets API, Credentials → Create API key, restrict it to the Sheets API, enter it on the settings screen (or with `php artisan google:configure`). Explains how to make a sheet "Anyone with the link can view".
   - No credentials of ours are included, and no Google values go in `.env`.
4. **docs/DECISIONS.md:**
   - safe ALTER instead of JSON or EAV
   - the source records layer
   - **deterministic identity instead of similarity thresholds**
   - soft deletes
   - review vs auto mode
   - OAuth as default with API key fallback, and why the service account was left out (it needs every sheet shared with its email)
   - secrets in the database, encrypted with `APP_KEY`; only `APP_KEY` and the database credentials live in `.env`
5. **setup.sh (Ubuntu 22.04/24.04).** One command does the full setup (including generating `APP_KEY`), can be run more than once safely, optionally asks for the Google values (hidden input, stored encrypted in the database), and prints the URL, the redirect URI to register, and the login.
6. **docker-compose.yml.**
7. **Demo:** 3 public sheets (shuffled and extra columns, headers at H99, two tables in one tab, formulas, a total row, a sheet without IDs), plus a seeded configuration with each identity mode, one table in auto mode, one source → two targets, and three sources → one target.
8. **Tests:**
   - matching per identity mode
   - normalization
   - table locating
   - schema manager safety
   - backfill
   - mass-removal guard
   - superseded and rejected logic
   - duplicates and restore

## 18. Out of scope (documented as future work)

- Similarity matching. Rejected on purpose, with reasons in DECISIONS.md.
- Merging duplicates into unique entities.
- In-cell or floating images.
- Multiple users and roles.
- Changing column types or renaming target columns.
- Service account authentication: the production option for fully unattended servers, left out because it requires sharing every sheet with the robot's email.

## 19. Build order

1. Migrations and models
2. Google client with `ApiKeyCredentials` + fake, `TableLocator`, `StructureInspector` (the public demo sheets run on the API key)
3. `ValueNormalizer`, `Fingerprinter`, `RecordMatcher`, with tests
4. `ChangeSetBuilder`, `ChangeApplier`, `SchemaManager`, `Backfiller`, with tests
5. `SyncFileJob`, scheduler, guards, notifications
6. UI: files → tabs → table detection → source table setup → mappings → review inbox → alerts → history
7. Demo sheets and seed, setup.sh, Docker
8. OAuth connection as the default (`OAuthCredentials`, connect/callback/reconnect flow, fallback logic, connection status UI)
9. Docs
10. *(If time allows)* system ID mode with `SystemIdWriter`

**If time gets tight**, cut in this order: system ID mode, then OAuth (the API key alone still runs the demo), then the multi-table detection grid (fall back to manual header row and range), then email notifications. Never cut the engine, the tests, or the setup script.
