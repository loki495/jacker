# Listing API

A small token-protected JSON API lets another program (a job-finding agent, for example) add listings. It is off until `API_TOKEN` is set in `.env`; with no token every `/api` request answers 404.

## Authentication and exposure

Send `Authorization: Bearer <API_TOKEN>`. The token is compared in constant time; a wrong or missing token gets 401. Requests are rate limited per client address (`API_RATE_LIMIT`, default 60 a minute, then 429), and one request carries at most `API_MAX_BATCH` listings (default 50). Generate a token with `php -r 'echo bin2hex(random_bytes(32)),"\n";'`.

The rest of the application has no login. If the API is reachable from the internet, expose only the `/api/*` path (for example a Cloudflare Access policy that lets that path through, with the bearer token as the credential) and keep every other path behind your access layer. The host must be listed in `ALLOWED_HOSTS`.

## Versioning

Every route lives under `/api/v1`. Within v1 changes only add (new optional fields, response keys or endpoints); a client should ignore response keys it does not know and compare `schema_version` from `/api/v1/info` to notice added fields. A removal, rename or change of meaning ships as `/api/v2` with v1 kept alongside.

## `GET /api/v1/info`

Returns the accepted fields (name, type, required, maximum, allowed values, description), the duplicate rule, limits and a `schema_version`. Fetch it before a run: `schema_version` changes whenever the field list does, and the description of each field says what the app can use.

## `GET /api/v1/profile`

What a search session should know before it looks: `about` (free text), `name`, `location`, `links`, `scoring` (the skill rubric, pay bands, work mode points, Eastern-hours penalty: how listings are ranked once found) and `accepted_regions`. Also `configured`, `source`, `updated_at` and a `version` hash that changes when any of it does. Email, phone and résumés are never included. The same data is the MCP tool `get_search_profile`.

The `about` text is edited in **Settings → Search profile**. If `SEARCH_PROFILE_DIBS_ID` is set in `.env`, that section becomes read-only and the text comes from that Dibs record instead: run `bin/profile-sync` on the host (it reads the record through Dibs' CLI and pipes it into `php artisan profile:set`), and run it again after editing the record.

## `GET /api/v1/listings`

Every tracked listing that has an application link: `id`, `application_url`, `company`, `title`, `status` and `missing`, the fields a POST could still fill (pay appears as `salary_min` and `salary_max` while the listing has none). Query: `page`, `per_page` (up to `API_MAX_PAGE`, default 500), `only_missing=1`. A finder compares its results to this list, skips known links that miss nothing it has, and sends the rest. The gaps that `only_missing` reports are the ones `php artisan listings:enrich` works on, which checks those links and fills their empty fields from the page.

## `POST /api/v1/listings`

Body: `{"listings": [ {"company": "Acme", "application_url": "https://...", ...}, ... ]}`.

- `company` and `application_url` are required to create a listing. `application_url` is the duplicate key, compared after normalization (scheme and host lowercased, trailing slash dropped).
- A new link creates a listing with status `saved`.
- A known link never creates a second listing and never overwrites anything. Fields that are empty on the existing listing are filled (an enum left `unspecified` counts as empty); pay (`salary_min`, `salary_max`, `currency`, `pay_period`) is filled together and only while the listing has no pay; `extra_fields` adds names that are not yet present.
- Status, applied state, scores, follow-up dates and archiving are never accepted. Keys outside the field list are discarded and reported in `ignored`. Put additional facts in `extra_fields` (name and string value pairs) and they are stored and shown.

The response is 200 with `schema_version`, a `summary` (`created`, `updated`, `unchanged`, `invalid`) and one entry per listing in order: `index`, `result`, `id`, `filled` (field names), `ignored` and `errors` (field to messages). One invalid listing does not stop the others, so a retry of the same batch is safe: created listings come back as `unchanged` or `updated`. A body without a `listings` array, or with more than the batch limit, is 422.

No link check or other outbound fetch is queued for API listings; use the Overview's bulk Check action for that.

## MCP

`POST /api/v1/mcp` is a stateless MCP server (Streamable HTTP, JSON replies, no GET stream) with the same bearer token. Tools: `get_search_profile`, `get_listing_schema`, `list_known_listings` (`page`, `per_page`, `only_missing`) and `upsert_listings` (`listings`); the `upsert_listings` input schema is generated from the field list. A malformed tool call returns `isError: true`; an unknown method or tool is a JSON-RPC error. Client setup: URL `https://<host>/api/v1/mcp` and header `Authorization: Bearer <API_TOKEN>`.

### MCP without an Authorization header

Some clients (chat apps that take only a connector URL) cannot send headers. Set `API_URL_TOKEN` in `.env` to a long random string and give such a client `https://<host>/api/v1/mcp/<API_URL_TOKEN>`. That route serves the same MCP tools, nothing else: the REST routes and `/api/v1/mcp` still need the bearer token, and the URL key is never accepted there. A wrong or unset key answers 404, and guesses count against the rate limit.

The key is part of the URL, so it can appear in the logs of anything on the path (Cloudflare, a reverse proxy, the client itself). Keep it separate from `API_TOKEN`, treat it as revocable (change the value in `.env` and the old URL stops working at once), and leave it empty when no such client exists.

## Debugging a remote client

Every call to the API (accepted or rejected) writes one line to `storage/logs/api-YYYY-MM-DD.log` (kept `API_LOG_DAYS`, default 14): time, method, path with the key replaced by `[key]`, status, duration, client address, user agent, the JSON-RPC method and tool, how many listings were sent and the created/updated/unchanged/invalid counts. Tokens, keys and listing contents are never written. A client that never reaches the server leaves no line, which is the evidence you need.

`GET /api/v1/ping/<API_URL_TOKEN>?q=<short token>` answers `{"ok":true,"server_time":...,"echo":"<q>"}` and logs `q`: give a client a unique value, have it quote the reply, and match both against the log. Read the log with `docker exec jacker-app sh -c 'tail -n 30 storage/logs/api-*.log'`.

