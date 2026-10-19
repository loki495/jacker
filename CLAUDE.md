# Jobs

Private single-user job tracker. Laravel 13, PHP 8.5, SQLite, Livewire 4 single-file class components, free Flux UI 2, Tailwind 4/Vite. Read `AGENTS.md` and `.claude/project.md` for operational rules; `README.md` documents setup and security configuration.

Business logic belongs in typed actions; third-party IO belongs in services called by actions. New PHP files use strict types. Related database writes use transactions. User-facing copy belongs in `lang/en/`. Components live under `resources/views/pages/`; the canonical layout is `layouts.app`.

`Opportunity` uses the `opportunities` table, separate from Laravel's queue jobs table. `OpportunityData::validate()` normalizes and validates input without writing; both the editor and CSV actions use it. `SaveOpportunity` records lifecycle changes transactionally. `has_applied` preserves applications with unknown dates. Scores are nullable 1–5. CSV imports re-read the session-bound private upload at commit, preserve unmapped fields, skip exact URL duplicates and require review of candidate duplicates. See `docs/csv.md` for the import/export contract.

The application deliberately has no authentication. Trusted network or authenticated proxy access is mandatory; host validation is not authentication. No real external actions or notifications without explicit approval.

Quality gate: Pint → PHPStan level 6 → Rector dry-run → Pest inside `jobs-app`. Rector must remain review-only. Keep tests isolated with the guard before RefreshDatabase traits run.

No commits or pushes are authorized by this file. Public exposure/deployment remains a separate explicit decision.
