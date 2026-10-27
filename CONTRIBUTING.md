# Contributing

Jacker is a personal, single-user project; issues and pull requests are welcome but may not be merged.

1. Follow the setup in the README and run PHP tools inside the container (`docker exec --user www-data jacker-app ...`).
2. Keep business logic in typed actions and third-party IO in services; Livewire pages stay thin.
3. Run the checks in this order before opening a pull request: Pint, PHPStan (level 6), Rector in dry-run (review its suggestions by hand, never apply blindly), Pest. CI runs the same steps and enforces the coverage floor.
4. Add or update tests with every change. Do not weaken, skip or delete an existing test to make a run pass. Tests use SQLite `:memory:` behind a guard; never point them at a real database.
5. Update the docs that your change makes wrong, and add a line to the changelog.
6. The application deliberately has no login. Do not add features that call external services or send messages without an explicit opt-in.

To try the app with fake data, use a disposable database and `php artisan db:seed --class=DemoSeeder` (see the README).
