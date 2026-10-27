# CSV import and export

Open `/import`, upload a UTF-8 CSV, map columns, and preview before importing. Comma,
semicolon and tab delimiters, UTF-8 BOMs, quoted cells and multiline quoted values are
supported. Empty/duplicate headers, malformed quoting, inconsistent record widths,
invalid encoding and configured limits produce validation errors. Defaults are 5 MB
and 1,000 data records; configure `csv.max_bytes` and `csv.max_rows` through Laravel
configuration. Preview pages contain 20 records; the complete upload stays on the
server's private local disk and is re-read and validated inside the import transaction.

Company is required. Map a core field only once. Unmapped columns are preserved in
`extra_fields`, including empty strings. Suggested aliases include company name, URL,
application URL, job title, pay range, type (work arrangement), responsabilities,
applied yet and match, plus position (title), salary min usd / salary max usd, application deadline, key requirements and fit summary (notes). Pay range
maps to salary notes: it does not invent numeric pay. Applied accepts yes/no, y/n,
true/false or 1/0 and preserves an unknown application date. Dates require ISO
`YYYY-MM-DD`; ambiguous dates are rejected. Enum labels accept case differences and
spaces/hyphens (for example `Full time`, `on-site`, `Applied`); pay periods also accept
labels such as hourly and yearly. Work arrangement also accepts `onsite` and loose values by prefix (`remote_first` is remote, `hybrid_after_training` is hybrid, `onsite_or_...` is on-site, `telework...` is remote); employment type maps `full_time_*` and `part_time_*` to their base value and `unclear` to unspecified. The original wording is not kept. Unsupported values produce row errors. Blank
currency, status, work arrangement, employment type, pay period and applied cells use
the core defaults; other empty core fields remain null.

Invalid rows block import until explicitly excluded. Exact normalized application URL
duplicates, including those in the upload, are always skipped. Company/title/location
matches are possible duplicates and skipped unless explicitly included. Duplicate
checks run again at commit, and database write failures roll back the entire import.
Importing never updates an existing opportunity or calls any external service.

`/export` streams every opportunity (including archived records) with stable editable
core field headers and a sorted union of extra keys. Extra headers use `extra:` to
avoid core-name collisions; the importer removes this prefix from preserved keys.
Keys that collide ignoring case, have trailing whitespace, or are empty use the
reserved reversible `extra-hex:` header prefix followed by the UTF-8 key's hexadecimal
bytes. This keeps the exported union valid without changing custom field names.
Internal IDs, timestamps and the derived normalized URL are not editable CSV fields.
Values beginning with spreadsheet operators after whitespace/control characters get
an apostrophe prefix; this protective apostrophe remains literal on reimport. Nested
extra values are exported as JSON strings. An identical export reimport skips exact
URL matches; records without URLs remain possible duplicates and default to skip.

Private uploads are removed after a successful import or replacement upload. Abandoned
uploads remain private on disk; an operator can remove old `csv-imports/*.csv` files
once no active import needs them. No browser-provided preview rows are accepted for
commit, and upload references are locked and bound to the current server session.
