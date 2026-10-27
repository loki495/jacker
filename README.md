# Jacker

[![CI](https://github.com/loki495/jacker/actions/workflows/ci.yml/badge.svg)](https://github.com/loki495/jacker/actions/workflows/ci.yml)

A private, single-user job application tracker built with Laravel 13, Livewire 4 single-file class components, free Flux UI, Tailwind CSS 4, and SQLite.

## Your workspace

Save opportunities before applying and track them through Saved, Preparing, Recruiter contact, Applied, Screening, Interviewing, Offer, Rejected, Withdrawn, and Closed. The overview includes a ranked “Apply next” list, application counts (the summary cards are buttons: click Recruiter / screening, In progress, Offers, Follow-ups due or Opportunities to filter the pipeline to them), due follow-ups, a searchable table and a board of cards, lightly tinted by status (one column on phones, up to four across on wide screens; the *Stage* dropdown above it picks which stage to show). Rows and cards link straight to the application page (↗) and have checkboxes: the bar that appears offers *Check links*, *Grade with AI* and *Close* for all selected jobs (nothing runs unless you click it). The status filter defaults to *Not closed, not applied yet*; *Everything not closed* and *All statuses* are one click away. The board displays the current page of results; filters and pagination apply to both views. Archive records to keep their details without cluttering the active workspace.

Each opportunity can hold:

- Company and role, separate company and application links, source, location, remote/on-site/hybrid arrangement, remote restrictions, employment type, and hours.
- Minimum and maximum pay, currency, pay period, original compensation text, and benefits.
- Responsibilities, requirements, contacts, notes, application deadline, follow-up date, and application date.
- Independent skill-match and desirability scores from 1 to 5, with notes for each; score badges are coloured from red (1) through amber (3) to green (5), and each opportunity page has a *Score desirability and fit with AI* button and an *Add from text* link that opens the Import page with that job preselected. An unscored opportunity remains distinct from a low score.
- Additional fields preserved from imports.

Only the company is required. “Already applied” can be true with no known application date. Changing status never invents a historical application date. Status changes are recorded in the opportunity history. Follow-ups are shown in the workspace; the app sends no reminders or messages.

Use **Import** to upload, map columns, inspect validation errors and duplicate candidates, then explicitly save reviewed rows. Unknown columns are preserved. Existing records are never overwritten by an import. **Export CSV** (the same page, titled Import / Export) downloads all opportunities, including archived records. See [CSV behavior and limits](docs/csv.md) for supported formats, duplicate rules and spreadsheet safety.

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

## Documentation

- [Access, security and configuration](docs/configuration.md): private access model, allowed hosts, HTTPS behind a proxy, environment variables, backups, timezone.
- [Features and background work](docs/features.md): job finder, queue, link checks, saved pages, AI reading, grading, settings, cover letters, interview questions.
- [CSV behavior and limits](docs/csv.md)
- [Contributing](CONTRIBUTING.md), [security policy](SECURITY.md), [changelog](CHANGELOG.md)

## Demo data

To see the app with fake listings, point `DB_DATABASE` at a disposable SQLite file, migrate, and run `php artisan db:seed --class=DemoSeeder`. It refuses to run if opportunities already exist and is never part of `DatabaseSeeder`; never run it against your own data.

## Quality checks

Run in this order:

```sh
docker exec --user www-data jacker-app ./vendor/bin/pint
docker exec --user www-data jacker-app ./vendor/bin/phpstan analyse --memory-limit=1G
docker exec --user www-data jacker-app ./vendor/bin/rector process --dry-run
docker exec --user www-data jacker-app ./vendor/bin/pest
```

Coverage (pcov is installed but off by default): `composer coverage` runs Pest with `-d pcov.enabled=1 --coverage --min=94`; the floor is the measured 94.2% of `app/` and CI enforces the same number, so raise it as coverage grows. Pushes to `main` and pull requests run the same checks in GitHub Actions (`.github/workflows/ci.yml`: build assets, Pint, PHPStan, Rector dry-run, `composer audit`, Pest with coverage), and Dependabot proposes weekly composer, npm and Actions updates.

Composer wrappers (`composer pint`, `composer phpstan`, `composer rector`, `composer pest`) call the same `docker exec jacker-app` commands if host Composer is available. Composer lifecycle hooks run PHP directly in the current environment, so ordinary installs inside Docker do not require Docker-in-Docker. Rector is review-only; inspect proposed changes and edit manually.

Pest uses SQLite `:memory:`. `tests/TestCase.php` checks the testing environment, default SQLite connection, in-memory database, and absence of URL/read/write overrides before Laravel initializes `RefreshDatabase`. Clear any application config cache before tests; the guard rejects cached production settings. Never point tests at the application's SQLite file. Tests cover opportunity actions, lifecycle and validation, Livewire screens, CSV import/export, rejected hosts, missing routes, and database guard failures. Browser checks must use a separate disposable database.
