# Changelog

## Unreleased

- Dependencies: patch Symfony Mime for [CVE-2026-45070](https://github.com/symfony/symfony/security/advisories/GHSA-vqc8-7275-q272).

- Optional Laravel AI provider processing for extraction, grading and letter suggestions, with per-feature Settings and the host bridge as default.
- Experimental opt-in similarities for jobs and past letters using SQLite vectors and local ranking.
- Portfolio documentation, architecture guide and screenshots with fake demo data.

- Mobile: stack job finder filters and prevent overflow in detail panels and queue actions.
- Job finder: LaraJobs added as a source (its home page, every open posting, with the employer's application link kept in the notes).
- Docs: README split into `docs/`, contributing and security files, demo seeder.
- Tests: board stage and selection, queue polling, shell markup, import scroll, CSV header aliases, cover letter commands; coverage floor 94%.
- Theme font now matches the declared Instrument Sans.

## 0.1.0

First public version: opportunity tracker with lifecycle history, ranked overview and board, CSV import/export, link checks, saved pages, AI-assisted extraction, scoring with editable criteria, interview question bank, per-job cover letters with PDF export, job finder, light/dark theme, GitHub Actions CI with a coverage floor.
