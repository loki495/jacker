# Access and configuration

This application deliberately has no login or user management. Access must remain private, protected by a trusted LAN boundary or an authenticated reverse proxy such as Cloudflare Access. An allowed hostname is routing validation, not authentication. Do not expose the application directly to the internet.

`ALLOWED_HOSTS` is a comma-separated list of exact lowercase hostnames, without schemes, ports, or wildcards. It defaults to `localhost,127.0.0.1`; other hosts receive HTTP 400. The portable compose file requires no external network or Traefik router. Machine-specific routing belongs in the ignored `docker-compose.override.yml`.

Before an approved HTTPS deployment, set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://your-private-host`, `ALLOWED_HOSTS`, `SESSION_SECURE_COOKIE=true`, and `TRUSTED_PROXIES` to the actual proxy IPs or CIDRs. Only explicitly configured proxies are trusted. Leave `SESSION_DOMAIN` unset for host-only cookies. Session cookies are encrypted, HttpOnly, and SameSite=Lax. Standard Laravel CSRF protection covers web/Livewire mutations. The local HTTP default disables only the Secure cookie flag. Keep the generated `APP_KEY` and `.env` private; preserve the key with backups.

The last trusted proxy must supply the client-facing HTTPS scheme and port, including when a tunnel connects to the proxy over HTTP. Laravel must recognize the request itself as secure so that signed upload URLs validate correctly. Forcing only the URL generator's scheme is insufficient. Keep exactly one router per hostname and entrypoint; use service-only Docker labels when central Traefik files own the routers.

`CSV_MAX_BYTES` defaults to 5,242,880 and `CSV_MAX_ROWS` to 1,000. Both are read through `config/csv.php`. CSV uploads remain on private storage and are removed on successful import or replacement; see the CSV guide for abandoned uploads.

SQLite defaults to `database/database.sqlite`. Back up the database together with `.env` and persistent uploads using a consistent SQLite backup process; test restores before relying on backups. Do not commit the database, secrets, or personal job data.

On phones the sidebar collapses into a slim bar with a menu button. The top bar has a light, dark and match-system theme switch; the choice is remembered in the browser. The dark styles live in `resources/css/dark.css`, which overrides the light colours in `resources/css/app.css`: add a matching rule there when you add a coloured rule to `app.css`.

Set `APP_TIMEZONE` to your local IANA timezone for status-history timestamps and daily follow-up boundaries. The portable default is `UTC`; tests explicitly use UTC and separately exercise timezone conversions.

Mail uses the log driver. Queues use the database driver and the `queue` compose service (`php artisan queue:work`) processes link checks; with `QUEUE_CONNECTION=sync` they run inline instead. Outbound requests include job discovery, link checks and rendering, and explicitly enabled AI providers. No notifications are configured.

## AI processing

The Settings page selects **Host bridge** (the default) or **Provider API** separately for page extraction, job grading, and cover-letter suggestions. Saving these preferences sends nothing; subsequent AI actions use them. Requests already queued keep their saved driver and API model. The host cover-letter agent/model/thinking controls apply only to the bridge.

API processing runs in `jacker-queue` using `laravel/ai`. Anthropic and OpenAI are supported. Put `ANTHROPIC_API_KEY` or `OPENAI_API_KEY` only in `.env`; keys are never saved in Settings. Choose a provider-compatible API model, rather than a CLI alias such as `haiku` or a Codex subscription. OpenAI API billing and credentials are separate from the Codex CLI. API requests may incur charges and send job text or letter drafts to the selected provider. OpenAI request storage is disabled in the SDK configuration.

Environment defaults, overridden by saved Settings:

| Feature | Driver | Provider | API model |
| --- | --- | --- | --- |
| Page extraction | `AI_EXTRACT_DRIVER=bridge` | `AI_EXTRACT_PROVIDER=anthropic` | `AI_EXTRACT_MODEL` |
| Job grading | `AI_SCORE_DRIVER=bridge` | `AI_SCORE_PROVIDER=anthropic` | `AI_SCORE_MODEL` |
| Letter suggestions | `AI_LETTER_DRIVER=bridge` | `AI_LETTER_PROVIDER=anthropic` | `AI_LETTER_MODEL` |

Use `sdk` for the API driver. API model defaults are declared in `config/ai.php`; configure a model available to your provider account. API processing uses the selected provider only and reports a failure if its key is missing. The host bridge retains its configured CLI fallback chains. Page rendering still runs through the host bridge when an extraction reads a live URL.

After changing environment/configuration values, clear any cached configuration and restart `jacker-queue`. No AI conversation tables are needed. The additive application migrations add the saved request driver and the optional similarity index.

## Similar jobs and past letters

The experimental similarity feature is off by default. Enable it in Settings, then click **Index existing jobs** to process current non-archived records. Later job-description and letter-draft changes queue incremental updates. Enabling the preference itself does not index existing data. Disabled indexing requests do nothing.

This feature uses `OPENAI_API_KEY`, with `EMBEDDINGS_PROVIDER=openai`, `EMBEDDINGS_MODEL=text-embedding-3-small`, and `EMBEDDINGS_DIMENSIONS=1536` as defaults. Embedding calls send source text to the provider and may incur charges. Compatible vectors are compared locally in SQLite/PHP; no vector database or external search service is needed. Changes to model/dimensions require re-indexing. Disabling similarities hides matches and stops generation, while retained vectors remain part of database backups. Past letters are linked for review and are not automatically included in AI prompts.
