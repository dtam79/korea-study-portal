# KG Portal Core v0.4.0 — REST API / Reviewed Publication

This version extends the successfully tested v0.3.0 domain package. It preserves all existing repository, field-validation, source, role and schema functionality, and adds live WordPress REST APIs and reviewer-approved public identity snapshots.

## Before installing

This ZIP is a **complete plugin folder**, intended to overlay `wp-content/plugins/kg-portal-core/` (not `wp-content/plugins/kg-portal-core/kg-portal-core/`). A backup is strongly recommended. The database migration is **non-destructive** (`dbDelta` adds a new table); it does **not** auto-publish private records. It is not production-tested yet.

Run from the DDEV project root:

```bash
mkdir -p ../kgp-backups

ddev export-db --file=../kgp-backups/before-v040.sql.gz

tar -czf ../kgp-backups/kg-portal-core-before-v040.tar.gz -C wp-content/plugins kg-portal-core

unzip -o ~/Downloads/kg-portal-core-v0.4.0.zip -d wp-content/plugins/

ddev wp kgp schema install

ddev wp kgp schema status

ddev wp kgp domain check

ddev wp kgp api check

ddev wp --user=localadmin kgp api smoke
```

**Expected after migration:** `20/20` tables, schema version `2026.11.10.2`.

The `api smoke` integration test uses real WordPress REST dispatch, real MariaDB records, review/publish, public search/detail, tuition filtering, and access enforcement. It runs only on `*.ddev.site` and only as a site admin. All synthetic data is wrapped in a transaction and rolled back. If it reports an error, do not commit; send the full error output for diagnosis.

## Public endpoints

Open in the browser (after DDEV starts):

- `https://korea-study-portal.ddev.site/wp-json/kgp/v1/catalog`
- `https://korea-study-portal.ddev.site/wp-json/kgp/v1/admissions`
- `https://korea-study-portal.ddev.site/wp-json/kgp/v1/catalog?locale=en&region=seoul&level=bachelor`

An empty result before real reviewer-approved content is expected. The smoke test rolls its example institutions back; do **not** treat an empty catalog as failed installation.

## Private endpoints

The authenticated routes are documented in [`docs/api-v1.md`](docs/api-v1.md). Cookie-authenticated browser code must send `X-WP-Nonce` and respect WordPress REST authentication. Never publish private editor or reviewer API keys in a public frontend.

## New public-information safety invariant

Existing v0.3.0 university/campus/program/intake/offering repository rows are mutable *private records*. Their names, links and status must not appear publicly until a reviewer approves an identity snapshot using a currently verified official source. This version adds the `entity_publications` table to preserve that distinction. Previously published, sourced field versions remain controlled by the existing `KGP_Public_Reader`.

A public offering must have approved identity snapshots for university, campus, program, intake and offering; the same verified source may be linked to several if it legitimately supports each. A private field draft never appears in the public API.

## Implementation coverage in this step

- Public browse/filter/sort/paginate by **offering** and full reviewed 14-section detail (only sections with source-verified approved values)
- Official Korean/English names plus reviewer-approved aliases; filter OR within each category, AND across categories
- Tuition matching uses original fee units; no estimated converted or aggregated amounts
- Date-only deadlines remain date-only, without a fabricated 23:59 time
- Admin entity CRUD wrapper, identity submission/rejection/publication, source/issuer actions, field review actions and assigned-editor permissions
- System-generated read-only public API, private login-required admin API, WordPress REST errors and optimistic version checks
- Live DDEV CLI smoke test and PHP structural preflight

## Remaining functions — preserved in full project scope

Other features from the supplied planning, functional and design specifications remain scheduled for subsequent phases: full FE screens and admin UI, translation draft/review workflows, CSV importer and histories/recovery, inquiry workflows, scheduled source checks, high-volume indexed search/caching, SEO/backups, and full production acceptance testing. No requested feature is removed because of this stage's boundaries.

## Local preflight

```bash
find wp-content/plugins/kg-portal-core -name '*.php' -print -exec php -l {} \;
php wp-content/plugins/kg-portal-core/tests/api-shape-smoke.php
```

`api-shape-smoke.php` is a static sanity check and **does not** replace `ddev wp --user=localadmin kgp api smoke`.

## Recovery

If installation introduces problems, restore the backed-up plugin folder and database from the files created before installing. This release does not have an automatic downgrade migration; do not manually drop the new table on a database that contains user data.
