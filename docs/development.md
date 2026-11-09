# Development and verification

Run in this order:

```sh
docker exec --user www-data jacker-app ./vendor/bin/pint
docker exec --user www-data jacker-app ./vendor/bin/phpstan analyse --memory-limit=1G
docker exec --user www-data jacker-app ./vendor/bin/rector process --dry-run
docker exec --user www-data jacker-app ./vendor/bin/pest
```

Coverage (pcov is installed but off by default): `composer coverage` runs Pest with `-d pcov.enabled=1 --coverage --min=94`; CI enforces a 94% floor for `app/`; raise it as coverage grows. Pushes to `main` and pull requests run the same checks in GitHub Actions (`.github/workflows/ci.yml`: build assets, Pint, PHPStan, Rector dry-run, `composer audit`, Pest with coverage), and Dependabot proposes weekly composer, npm and Actions updates.

Composer wrappers (`composer pint`, `composer phpstan`, `composer rector`, `composer pest`) call the same `docker exec jacker-app` commands if host Composer is available. Composer lifecycle hooks run PHP directly in the current environment, so ordinary installs inside Docker do not require Docker-in-Docker. Rector is review-only; inspect proposed changes and edit manually.

Pest uses SQLite `:memory:`. `tests/TestCase.php` checks the testing environment, default SQLite connection, in-memory database, and absence of URL/read/write overrides before Laravel initializes `RefreshDatabase`. Clear any application config cache before tests; the guard rejects cached production settings. Never point tests at the application's SQLite file. Tests (`tests/Feature`) cover opportunity actions, lifecycle and validation, Livewire screens and page behaviour, CSV import/export and aliases, saved-page import, link checks, listing enrichment (gap selection, cooldown, fill-only chaining, dead links), job board and Job finder, scoring and AI extraction/drivers, embeddings and settings, cover letters and their commands, interview questions, mailbox and LinkedIn link scans, the Markdown PDF renderer and application materials (save, preview, download, mark as used), the agent worker's shell functions (stubbed `sessioneer`, render and artisan; nothing live) and the agent request flow, rejected hosts, missing routes, and database guard failures. Proxy tests verify that forwarded scheme and port affect generated URLs only for trusted clients. A separate-process bootstrap test proves that unsafe database settings are rejected before the refresh trait starts; a sentinel stops the process before any connection even if the guard regresses. Fill completion tests exercise the real command's success, failure and queued-request refusal. Browser checks must use a separate disposable database.

## Demo data

Run the demo seeder only against a disposable database. It refuses an existing opportunity set and is never called by `DatabaseSeeder`. For example, inside `jacker-app`, set `DB_DATABASE=/tmp/jacker-demo.sqlite`, `DB_URL=`, `SESSION_DRIVER=array`, and `CACHE_STORE=array` for each migration/seeding command. Create the empty file first and never change the running application's `.env` to produce screenshots.

The README screenshots use fake jobs from `DemoSeeder`, sample queue rows, and a sample cover letter in SQLite `:memory:`. They contain no personal job data or API responses.
