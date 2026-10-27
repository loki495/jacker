# Access and configuration

This application deliberately has no login or user management. Access must remain private, protected by a trusted LAN boundary or an authenticated reverse proxy such as Cloudflare Access. An allowed hostname is routing validation, not authentication. Do not expose the application directly to the internet.

`ALLOWED_HOSTS` is a comma-separated list of exact lowercase hostnames, without schemes, ports, or wildcards. It defaults to `localhost,127.0.0.1`; other hosts receive HTTP 400. The portable compose file requires no external network or Traefik router. Machine-specific routing belongs in the ignored `docker-compose.override.yml`.

Before an approved HTTPS deployment, set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://your-private-host`, `ALLOWED_HOSTS`, `SESSION_SECURE_COOKIE=true`, and `TRUSTED_PROXIES` to the actual proxy IPs or CIDRs. Only explicitly configured proxies are trusted. Leave `SESSION_DOMAIN` unset for host-only cookies. Session cookies are encrypted, HttpOnly, and SameSite=Lax. Standard Laravel CSRF protection covers web/Livewire mutations. The local HTTP default disables only the Secure cookie flag. Keep the generated `APP_KEY` and `.env` private; preserve the key with backups.

The last trusted proxy must supply the client-facing HTTPS scheme and port, including when a tunnel connects to the proxy over HTTP. Laravel must recognize the request itself as secure so that signed upload URLs validate correctly. Forcing only the URL generator's scheme is insufficient. Keep exactly one router per hostname and entrypoint; use service-only Docker labels when central Traefik files own the routers.

`CSV_MAX_BYTES` defaults to 5,242,880 and `CSV_MAX_ROWS` to 1,000. Both are read through `config/csv.php`. CSV uploads remain on private storage and are removed on successful import or replacement; see the CSV guide for abandoned uploads.

SQLite defaults to `database/database.sqlite`. Back up the database together with `.env` and persistent uploads using a consistent SQLite backup process; test restores before relying on backups. Do not commit the database, secrets, or personal job data.

The default order is skill match plus desirability, highest first.

On phones the sidebar collapses into a slim bar with a menu button. The top bar has a light, dark and match-system theme switch; the choice is remembered in the browser. The dark styles live in `resources/css/dark.css`, which overrides the light colours in `resources/css/app.css`: add a matching rule there when you add a coloured rule to `app.css`.



Set `APP_TIMEZONE` to your local IANA timezone for status-history timestamps and daily follow-up boundaries. The portable default is `UTC`; tests explicitly use UTC and separately exercise timezone conversions.








Mail uses the log driver. Queues use the database driver and the `queue` compose service (`php artisan queue:work`) processes link checks; with `QUEUE_CONNECTION=sync` they run inline instead. Link checks are the only outbound requests, to the job pages you give or save. No notifications are configured.
