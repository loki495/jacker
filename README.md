# Jacker

A private, single-user job application tracker built with Laravel 13, Livewire 4 single-file class components, free Flux UI, Tailwind CSS 4, and SQLite.

## Your workspace

Save opportunities before applying and track them through Saved, Preparing, Applied, Screening, Interviewing, Offer, Rejected, Withdrawn, and Closed. The overview includes a ranked “Apply next” list, application counts, due follow-ups, a searchable table and a status board. The status filter defaults to *Not closed, not applied yet*; *Everything not closed* and *All statuses* are one click away. The board displays the current page of results; filters and pagination apply to both views. Archive records to keep their details without cluttering the active workspace.

Each opportunity can hold:

- Company and role, separate company and application links, source, location, remote/on-site/hybrid arrangement, remote restrictions, employment type, and hours.
- Minimum and maximum pay, currency, pay period, original compensation text, and benefits.
- Responsibilities, requirements, contacts, notes, application deadline, follow-up date, and application date.
- Independent skill-match and desirability scores from 1 to 5, with notes for each. An unscored opportunity remains distinct from a low score.
- Additional fields preserved from imports.

Only the company is required. “Already applied” can be true with no known application date. Changing status never invents a historical application date. Status changes are recorded in the opportunity history. Follow-ups are shown in the workspace; the app sends no reminders or messages.

Use **Import** to upload, map columns, inspect validation errors and duplicate candidates, then explicitly save reviewed rows. Unknown columns are preserved. Existing records are never overwritten by an import. **Export CSV** downloads all opportunities, including archived records. See [CSV behavior and limits](docs/csv.md) for supported formats, duplicate rules and spreadsheet safety.

## Local setup

Install Docker Engine and Docker Compose. No host PHP, Composer, or Node installation is needed.

```sh
cp .env.example .env
chmod 600 .env
docker compose build app
docker compose up -d app
docker exec --user www-data jacker-app composer install --no-interaction
docker exec --user www-data jacker-app php artisan key:generate
touch database/database.sqlite
docker exec --user www-data jacker-app php artisan migrate --force
docker compose run --rm node npm ci
docker compose run --rm node npm run build
```

Open <http://localhost:8096>. The default port binds only to loopback. Set `APP_PORT` in `.env` if that port is occupied; also update `APP_URL`. `APP_UID` and `APP_GID` default to 1000 and should match the repository owner before building. The app binds source, dependencies, SQLite, and storage from this checkout. Keep `storage/` and `bootstrap/cache/` writable by the mapped user.

`docker/setup-dev-container.sh` configures the PHP/Apache image during its build. The optional `node` tools profile builds versioned Vite assets; no permanent Vite process is required. After changing frontend files, run the build again. Do not install a second Alpine instance: Livewire provides it.

## Access and configuration

This application deliberately has no login or user management. Access must remain private, protected by a trusted LAN boundary or an authenticated reverse proxy such as Cloudflare Access. An allowed hostname is routing validation, not authentication. Do not expose the application directly to the internet.

`ALLOWED_HOSTS` is a comma-separated list of exact lowercase hostnames, without schemes, ports, or wildcards. It defaults to `localhost,127.0.0.1`; other hosts receive HTTP 400. The portable compose file requires no external network or Traefik router. Machine-specific routing belongs in the ignored `docker-compose.override.yml`.

Before an approved HTTPS deployment, set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://your-private-host`, `ALLOWED_HOSTS`, `SESSION_SECURE_COOKIE=true`, and `TRUSTED_PROXIES` to the actual proxy IPs or CIDRs. Only explicitly configured proxies are trusted. Leave `SESSION_DOMAIN` unset for host-only cookies. Session cookies are encrypted, HttpOnly, and SameSite=Lax. Standard Laravel CSRF protection covers web/Livewire mutations. The local HTTP default disables only the Secure cookie flag. Keep the generated `APP_KEY` and `.env` private; preserve the key with backups.

The last trusted proxy must supply the client-facing HTTPS scheme and port, including when a tunnel connects to the proxy over HTTP. Laravel must recognize the request itself as secure so that signed upload URLs validate correctly. Forcing only the URL generator's scheme is insufficient. Keep exactly one router per hostname and entrypoint; use service-only Docker labels when central Traefik files own the routers.

`CSV_MAX_BYTES` defaults to 5,242,880 and `CSV_MAX_ROWS` to 1,000. Both are read through `config/csv.php`. CSV uploads remain on private storage and are removed on successful import or replacement; see the CSV guide for abandoned uploads.

SQLite defaults to `database/database.sqlite`. Back up the database together with `.env` and persistent uploads using a consistent SQLite backup process; test restores before relying on backups. Do not commit the database, secrets, or personal job data.

The default order is skill match plus desirability, highest first.

The top bar has a light, dark and match-system theme switch; the choice is remembered in the browser. The dark styles live in `resources/css/dark.css`, which overrides the light colours in `resources/css/app.css`: add a matching rule there when you add a coloured rule to `app.css`.

**Link checks** (sidebar) fetch an application page in the background to see whether it is still live (404/410 are flagged as gone, and so are pages that load but say the job is gone, such as "job not found", or whose structured data has expired; sites that refuse automated requests, such as LinkedIn, are flagged as blocked) and fill in only the fields that are still empty from the page's structured job data (schema.org JobPosting). Links on Ashby, Greenhouse and Lever are checked through those companies' public job-board APIs instead of their pages (the pages are JavaScript shells that answer 200 even for a closed job), which also fills title, location, pay and the description. Nothing existing is overwritten. A job whose link is gone (404/410) is closed automatically, unless it is already applied or past the saved/preparing stages; other failures are only flagged. Check one job from its page, all active jobs from the log page, or paste job page links on the Import page to create new listings from them. The log page can clear finished entries (older than 30 or 7 days, or all); queued and running checks and ones whose AI request is running are kept, a waiting AI request is cancelled with its check, and jobs are never touched. Checks are spaced a few seconds apart (`LINKCHECK_SPACING`), never run on a schedule, and refuse private or reserved network addresses.

Set `APP_TIMEZONE` to your local IANA timezone for status-history timestamps and daily follow-up boundaries. The portable default is `UTC`; tests explicitly use UTC and separately exercise timezone conversions.

**Saved pages and pasted text.** The Import page also has *Add from a saved page or text* (one file box: `.csv`/`.tsv` start the CSV import, `.html`/`.htm`/`.mhtml`/`.mht`/`.txt` are imported as a posting): save a job page in your browser (HTML or MHTML), or paste the posting text, and import it with no network request. Structured job data is read at once; otherwise the text is kept and queued for AI reading. Choose an existing job to fill its empty fields, or leave it empty to create a new listing. PDF files are not read yet (no PDF text tool is installed).

**Render page.** Pages that build their content with JavaScript give the plain fetch almost no text. Each fetched row in the link-check log has a *Render page* button that queues a browser render: the host bridge (`bin/ai-bridge`) opens the link in headless Chromium through Playwright, waits for the page to settle and replaces the check's saved text with what the browser shows; *Read with AI* then reads that text. It is on demand only (never in bulk or on a schedule), skips saved text, and does not load images, media, fonts or stylesheets. Every address the browser touches, including redirects and scripts, is vetted by the same public-address guard as link checks (`php artisan render:allow`); a DNS answer that changes between the check and the browser's own lookup is not covered. One-time host setup: `cd tools/render && npm install`; it uses the newest Chromium in `~/.cache/ms-playwright` (or `RENDER_CHROME`) and `RENDER_TIMEOUT_MS` (default 30000). The systemd unit below runs it.

**Read with AI.** When a live page has no structured data, the link-check log keeps its readable text (the text is kept for every fetched page, with or without structured data, so you can also request an AI pass on a page whose structured data was incomplete; a pass only fills empty fields. Pages read through a job-board API keep the posting text the API returned) (capped by `LINKCHECK_MAX_TEXT_CHARS`) and offers a *Read with AI* button. Clicking it queues one request (`ai_requests`) and the row shows its state (waiting for the bridge, reading, then the result or the failure reason; the page refreshes itself, and a failed request can be retried, and the model's raw reply is kept and shown under *Raw reply*); nothing runs in bulk or on a schedule. A host-side bridge (`bin/ai-bridge`, needs `docker`, `jq` and `sessioneer` with `--wait` on the host; run it by hand, or with `--loop`; `docker/jacker-ai-bridge.service` is a systemd user unit that runs the loop: symlink it into `~/.config/systemd/user/`, edit the `ExecStart` path to your checkout, then `systemctl --user daemon-reload && systemctl --user enable --now jacker-ai-bridge`) takes queued requests through `php artisan ai:claim`, runs them in a one-off Sessioneer session (`AI_AGENT`, `AI_MODEL`, default Claude Haiku) and returns the reply through `php artisan ai:complete <id>`. Only valid job fields are accepted, and only empty fields of the job are filled.

Mail uses the log driver. Queues use the database driver and the `queue` compose service (`php artisan queue:work`) processes link checks; with `QUEUE_CONNECTION=sync` they run inline instead. Link checks are the only outbound requests, to the job pages you give or save. No notifications are configured.

## Quality checks

Run in this order:

```sh
docker exec --user www-data jacker-app ./vendor/bin/pint
docker exec --user www-data jacker-app ./vendor/bin/phpstan analyse --memory-limit=1G
docker exec --user www-data jacker-app ./vendor/bin/rector process --dry-run
docker exec --user www-data jacker-app ./vendor/bin/pest
```

Composer wrappers (`composer pint`, `composer phpstan`, `composer rector`, `composer pest`) call the same `docker exec jacker-app` commands if host Composer is available. Composer lifecycle hooks run PHP directly in the current environment, so ordinary installs inside Docker do not require Docker-in-Docker. Rector is review-only; inspect proposed changes and edit manually.

Pest uses SQLite `:memory:`. `tests/TestCase.php` checks the testing environment, default SQLite connection, in-memory database, and absence of URL/read/write overrides before Laravel initializes `RefreshDatabase`. Clear any application config cache before tests; the guard rejects cached production settings. Never point tests at the application's SQLite file. Tests cover opportunity actions, lifecycle and validation, Livewire screens, CSV import/export, rejected hosts, missing routes, and database guard failures. Browser checks must use a separate disposable database.
