# Jacker project instructions

Delivery: direct push. Remote `origin` is github.com/loki495/jacker (public); `main` mirrors what is published and `local` is the permanent local branch. Confirm the exact remote and branch before every push.

Read `CLAUDE.md` and `.claude/project.md` before substantive work. This is a Laravel/Livewire project. Follow the global instructions in `~/dotfiles/ai/CLAUDE.md` when available.

- Run PHP, Composer, Artisan, and quality tools in `jacker-app`, using `docker exec --user www-data jacker-app ...`.
- Preserve the no-login, single-user model. Private network/proxy access is required. Do not expose a public hostname without explicit approval.
- Use typed action `handle()` methods for business logic, thin Livewire components, enums for fixed values, and transactions for related writes.
- Use Livewire 4 single-file class components and suitable free Flux components. Avoid paid Flux and duplicate Alpine installations.
- Run Pint → PHPStan level 6 → Rector dry-run → Pest. Review Rector changes manually. Never weaken, remove, skip, or bypass tests to pass checks.
- Tests must use SQLite `:memory:`. Preserve the pre-migration database guard in `tests/TestCase.php`.
- Keep `.env`, SQLite data, dependencies, built assets, and local compose overrides ignored. Keep repository documentation current with changes.
- Never commit unless asked. Never push without stating the exact remote and branch and receiving explicit confirmation. Do not rewrite shared history without explicit confirmation.
