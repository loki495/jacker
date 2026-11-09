# Changelog

## Unreleased

- API call log (`storage/logs/api-*.log`, no secrets or contents) and a `ping` route for checking that a remote client really reaches the server.
- MCP at a secret URL (`/api/v1/mcp/<API_URL_TOKEN>`) for connector UIs that cannot send an Authorization header.
- Search profile: a Settings section (or a Dibs record copied in by `bin/profile-sync`) served with the grading criteria, name, location and links by `GET /api/v1/profile` and the `get_search_profile` MCP tool.
- Listing API (`/api/v1`): `GET /info` describes the accepted fields, `GET /listings` lists known links with their missing fields, `POST /mcp` serves the same as an MCP server, and `POST /listings` creates listings or fills only the empty fields of a known application link, behind a bearer token (`API_TOKEN`, off by default). See `docs/api.md`.
- Mail leads: read-only IMAP scan of job alert emails (on demand and every 30 minutes from the scheduler), kept LinkedIn alert links scanned in a signed-in browser by a host reader, AI split of the scanned posting, and an apply-link confirmation before the agent apply run.
- Installable PWA with iOS pull-to-refresh; `csv:import` command for headless import.
- Application materials: one detail-page section with Resume and Cover letter tabs (Markdown editing, preview, PDF download, version picker with generated, edited and general resume), a Markdown to PDF renderer (Liberation Sans, real text layer), and Mark as used, which freezes the chosen PDFs and records the choice; the three new columns export read-only in CSV and are ignored on import.
- Private search: the agent worker syncs the general resume into storage and writes resume and letter Markdown the editors preload from.

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
