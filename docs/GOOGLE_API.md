# Google credentials

Sheets Sync connects to Google with your **own** credentials. There are two options, and you can use both:

| | **Option A: OAuth (recommended)** | **Option B: API key (fallback)** |
|---|---|---|
| Reads | **private** sheets your Google account can open | only sheets shared as **"Anyone with the link can view"** |
| Lists your spreadsheets ("Fetch from Google") | yes | no (add sheets by URL) |
| Writes (system ID mode) | yes, if you connect with write access | no |
| Time to set up | about 10 minutes | about 2 minutes |

With both set, OAuth is used first. The API key is used automatically when OAuth isn't connected, has expired, or can't open a file. The sync history shows which credential was used, and an alert tells you when the fallback is in use.

**Why can't an API key read private sheets?** An API key identifies a Google Cloud *project*, not a person. To open a private sheet, Google needs to know *who* is asking, and OAuth provides that. With OAuth, the app acts as your account, so **no sheet has to be shared with any email**.

**Where the values go:** paste them into **Settings → Google connection** in the admin panel, or run `php artisan google:configure`. They are stored **encrypted in the database** with the app's `APP_KEY` and are never written to `.env`. After saving, the screen only shows a masked hint, and changing a value asks for your password again.

> Google renames menus in the Cloud Console from time to time. If a label below doesn't match exactly, look for the closest one.

---

## Step 1: Create a project and enable the APIs (both options)

1. Open <https://console.cloud.google.com> and sign in.
2. In the project picker at the top, click **New project**, name it (e.g. `sheets-sync`), and click **Create**. Make sure it's selected.
3. Go to **APIs & Services → Library** and enable:
   - **Google Sheets API** (needed for both options)
   - **Google Drive API** (only for OAuth, so "Fetch from Google" can list your spreadsheets)

## Option A: OAuth client

### A1. Consent screen

1. Go to **Google Auth Platform** (older consoles: **APIs & Services → OAuth consent screen**) and click **Get started**.
2. **App information:** app name `Sheets Sync`, and your email as the support email.
3. **Audience:** choose **External** (or Internal if you use Google Workspace and only need your organization).
4. **Contact information:** your email. Then accept and create.
5. **Audience → Test users → Add users:** add the Google account(s) that will connect. While the app is in *Testing*, only test users can sign in.
6. **Data access → Add or remove scopes** (optional in Testing, but it makes the consent screen clearer). The app requests:
   - `https://www.googleapis.com/auth/spreadsheets.readonly`: read sheets
   - `https://www.googleapis.com/auth/drive.metadata.readonly`: list your spreadsheets
   - `openid`, `email`: show which account is connected
   - `https://www.googleapis.com/auth/spreadsheets`: only when you click **Connect with write access** (system ID mode)

### A2. OAuth client ID

1. Go to **Google Auth Platform → Clients** (older consoles: **APIs & Services → Credentials → Create credentials → OAuth client ID**).
2. Click **Create client**, application type **Web application**, name `Sheets Sync`.
3. Under **Authorized redirect URIs**, click **Add URI** and paste the redirect URI shown in **Settings → Google connection**. It is your app URL followed by `/google/callback`:
   - Docker: `http://localhost:8000/google/callback`
   - `setup.sh`: printed at the end, e.g. `http://203.0.113.10/google/callback`

   It must match exactly (scheme, host, port, path). Changes can take a few minutes to apply.
4. Click **Create**, then **Download JSON**, or copy the **Client ID** and **Client secret**. Keep the secret private: don't commit it, email it or paste it into chats. If it leaks, open the client, click **Add secret**, and delete the old one.

### A3. Connect

1. In the admin panel: **Settings → Google connection → Set OAuth client**. Paste the downloaded JSON (the app reads the ID and secret, and warns if the redirect URI isn't registered on the client), or fill in the client ID and secret. Confirm with your password.
2. Click **Connect Google account**, choose a test user, and click **Allow**. You're sent back to the settings page with the status **Connected**.
3. Use **Connect with write access** instead only if you want **system ID mode** (the app writes a `_sync_id` column into sheets without a natural ID).

From the terminal instead: `php artisan google:configure --client-json=/path/to/client.json`, then connect in the panel. The consent step needs a browser.

### A4. Testing mode and token expiry

While the consent screen's publishing status is **Testing**, Google lets refresh tokens for test users expire after about **7 days**. When that happens:
- the connection shows **Expired**;
- an alert and a notification ask you to **Reconnect Google account** (one click);
- until then, the API key fallback (if set) keeps public sheets syncing, and private sheets pause.

For long-term use, publish the app (Google may require verification for the Drive scope), or use the API key with public sheets.

## Option B: API key

1. Go to **APIs & Services → Credentials → Create credentials → API key**.
2. Click **Edit API key**. Under **API restrictions**, choose **Restrict key** and select **Google Sheets API**. Save.
3. In the admin panel: **Settings → Google connection → Set API key** (confirm with your password), then click **Test key**.
4. Make each sheet readable by the key: in Google Sheets, **Share → General access → Anyone with the link → Viewer**.

From the terminal: `php artisan google:configure --skip-oauth` (the input is hidden).

---

## Troubleshooting

| Message | Cause and fix |
|---|---|
| `redirect_uri_mismatch` on Google's page | The redirect URI isn't registered, or doesn't match exactly. Copy it from Settings → Google connection into the OAuth client (A2.3). |
| `access_denied` / "app has not completed verification" | The account isn't a test user. Add it under Audience → Test users (A1.5). |
| "Google did not return a refresh token" | Google only sends one with a fresh consent. Remove the app at <https://myaccount.google.com/permissions> and connect again. |
| "The sheet is not public…" | The API key can only read sheets shared as "Anyone with the link can view". Share it that way, or connect OAuth. |
| "The API key was rejected" / "not allowed to use the Google Sheets API" | The key is wrong, deleted, or restricted to another API. Check B2. |
| "not accessible with this Google account" | The connected account can't open the file. Share it with that account, or reconnect with the right one. |
| Connection **Expired** after about a week | Testing-mode token expiry (A4). Click Reconnect. |
| "Credentials can't be read, re-enter them" | `APP_KEY` changed since the values were saved (e.g. a new server or a restored backup). Enter them again. Back up `APP_KEY` together with the database. |
| "Google rate limit reached" | Too many requests per minute. The client retries with growing delays, and the next sync tries again. |
