# KG Portal Core — BE-02: Schema Installer

This **adds foundational storage only**. It DOES NOT yet implement application CRUD, public queries/search, review/approval workflows, translation transitions, CSV processing, privacy enforcement, scheduled jobs, or frontend UI. None of those required features have been removed from scope. Do not use this as a public production implementation.

## Install into your existing DDEV project (Mac)

1. Work from `~/Projects/korea-study-portal`; first back up your local DB:

   ```bash
   cd ~/Projects/korea-study-portal
   mkdir -p ../kgp-backups
   ddev export-db --file=../kgp-backups/before-kgp-schema.sql.gz
   ```

2. Download `kg-portal-be02-schema.zip` from ChatGPT. From the project root, assuming the file is in `~/Downloads`:

   ```bash
   cp wp-content/plugins/kg-portal-core/kg-portal-core.php ../kgp-backups/kg-portal-core.php.before-be02
   unzip -o ~/Downloads/kg-portal-be02-schema.zip -d wp-content/plugins/
   ```

3. Run the idempotent table installer (it does not drop tables):

   ```bash
   ddev wp kgp schema install
   ddev wp kgp schema status
   ddev wp option get kgp_db_schema_version
   ddev wp db prefix
   ```

4. Verify the PHP code and commit:

   ```bash
   ddev exec php -l wp-content/plugins/kg-portal-core/kg-portal-core.php
   ddev exec php wp-content/plugins/kg-portal-core/tests/schema-shape-smoke.php
   git add wp-content/plugins/kg-portal-core
   git commit -m "feat(be): add KGP foundational domain schema"
   ```

If `wp kgp schema` is not recognized, verify plugin activation with `ddev wp plugin is-active kg-portal-core`. If the database reports missing tables, stop and check `ddev logs -s web`; do not retry with destructive SQL.

## Schema structure (19 tables)

- Private core identifiers: `universities`, `campuses`, `programs`, `intakes`, `offerings`
- Public evidence: `issuers`, `sources`, `field_version_sources`
- Per-field versioning: `field_entries`, `field_versions`, `translations`
- Publication projection (currently empty): `offering_search`
- Operator assignments, history: `user_assignments`, `audit_events`
- CSV staging: `import_batches`, `import_rows`
- Private inquiry storage: `inquiries`, `inquiry_events`
- Recheck management: `recheck_tasks`

All names are prefixed with the active `$wpdb->prefix . 'kgp_'` (typically `wp_kgp_`).

## Non-negotiable implementation rules

- Never expose entity base rows or field draft records in a public API. Use a publication service to approve **per-field versions and source validity**, then build a public projection; also enforce approval at read time. `offering_search.is_public` **alone is not sufficient**.
- Relation integrity must be verified by a domain/repository layer before data writes. WordPress `dbDelta` does not manage foreign key constraints, so do not assume DB-level FK validation.
- Field data `null` / unpublished / verified zero are not interchangeable. Every item needs a data status and evidence links.
- Currency remains KRW and source units must be preserved; no estimated totals, inferred cost conversion, or derived scholarship-net amount.
- Dates need original precision, timezone and official correction evidence; do not assume 23:59 for unknown cutoffs.
- Version history must be immutable on publication; restore creates a **new draft** and must pass review again.
- Public publication, query, permission, field validation and transaction logic are **next milestones**, not implemented here.
- Do not collect real personal inquiries before privacy policy/retention, authorization, data minimization and deletion are implemented and approved.
- In test cases, never use real university assertions without approved official sources.

## Source of requirements

`한국 대학 유학 정보 포털 기능명세서 v1.3`, sections 2, 3, 4, 7–12 and acceptance cases T-01 through T-19. Schema structure is an **implementation proposal**, not a pre-approved database design in the source document.
