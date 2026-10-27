# Security policy

Jacker has no authentication by design: it is a private, single-user tool that must sit behind a trusted network boundary or an authenticated reverse proxy. Exposing it directly to the internet is unsupported. See `docs/configuration.md` for allowed hosts, HTTPS and proxy settings.

## Reporting a vulnerability

Please report privately through GitHub's "Report a vulnerability" (private security advisory) on this repository rather than a public issue. Include the affected version or commit, reproduction steps and the impact. Expect an acknowledgement within a week.

## Scope notes

- Link checks fetch only public addresses (private, reserved and loopback ranges are blocked on every redirect hop, and the connection is pinned to the vetted address).
- Text from job pages is treated as untrusted data when it is passed to AI agents: it is fenced, the agents run without tools, and their replies are whitelisted before anything is saved.
- Do not commit `.env`, the SQLite database or uploaded files.
