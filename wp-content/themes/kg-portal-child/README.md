# KG Portal FE v0.5.0 - public screens P01-P04

**Depends on**: WordPress 7+, active Kadence Theme + `kg-portal-child`, installed/active `kg-portal-core` version 0.4.0 or newer, DDEV. Does not replace or modify the KGP Core plugin or its 20 tables.

## Installation (run in the DDEV project root)

1. Back up the currently active theme and DB:

```sh
mkdir -p ../kgp-backups
ddev export-db --file=../kgp-backups/before-fe-v050.sql.gz
tar -czf ../kgp-backups/kg-portal-child-before-fe-v050.tar.gz -C wp-content/themes kg-portal-child
```

2. Extract `kg-portal-child` directory into `wp-content/themes/` (not `wp-content/plugins/`). This overwrites the child theme's `style.css` and adds PHP/JS/CSS files. Existing custom edits to `kg-portal-child` should be reviewed first.

3. Confirm theme active and flush rewrite rules:

```sh
ddev wp theme activate kg-portal-child
ddev wp rewrite flush
```

4. Validate the new code:

```sh
ddev exec php wp-content/themes/kg-portal-child/tests/static-smoke.php
ddev wp eval-file wp-content/themes/kg-portal-child/tests/wp-integration-smoke.php
```

5. Browse these routes:

- `/ko/` and `/en/` - P01 home (live published `open` offerings, no invented cards)
- `/ko/universities/` - P02 search (all backend filter codes, per-offering results, verified original tuition units)
- `/ko/universities/{university_public_id}/?offering={offering_public_id}` - P03 details and official source links
- `/ko/admissions/` - P04 admission schedule

Routes are also available under `/en/`.

## Current implemented behavior

- Kadence child theme with project-scoped styles and centered max 480px single public column at all viewport sizes.
- Native accessible inline `<details>` filters; Apply with GET, Reset all link, folding retains draft input and existing results. No modal/panel.
- Filters: search text, region, academic field, degree/course, teaching language, admission status, certification, English-only, tuition unit and min/max; validated tuition sort requires a single level plus unit.
- Public read traffic uses `rest_do_request()` to KGP Core 0.4.0 **public** endpoints; no access to raw tables, admin endpoints or unapproved metadata.
- Identity and per-field evidence source links, checked dates and applicability details from current approved responses. Missing fields/sections are hidden. Old/unreviewed English translations are not shown without a warning.
- Program/offering selection never silently changes an invalid offering. Full-page GET navigation supports Back, bookmarks and sharing without JavaScript.
- No user account, internal application form, estimated cost totals, arbitrary unit conversion, fabricated deadlines or sample university data.

## Explicitly not yet complete (remaining REQUIRED scope, not removed)

- P05/P06 guide *content*, P07 FAQ content, P08/P09 operator inquiry storage/form, P10 policies. Entry routes exist but show a transparent pending state; no user data is accepted without the corresponding server-side flow.
- A01-A09 admin UI, field and translation approval consoles, CSV import UI, full SEO, scheduled rechecks, comprehensive E2E in an actual browser, and all remaining feature-spec IDs.
- Actual copy review, 20 real universities and original source approvals, full field-label translation catalog and Kadence Pro/Theme Kit dynamic configuration verification.

The above features must be implemented before claiming full project completion. This package contains **only FE-01 and the P01-P04 core front-end phase**. It does not change requirements or replace server-side source review.

## Troubleshooting

- 404 on `/ko/`: run `ddev wp rewrite flush`. Check Kadence child theme is active.
- The homepage is empty: publish all five linked KGP entity identities through the existing reviewer workflow, and publish the required evidence-backed field versions.
- A public route returns an API error: check `ddev wp kgp api check`, `ddev wp --user=localadmin kgp api smoke`, and WordPress debug logs.
- Blank page after PHP update: restore the child theme backup, then run `ddev wp theme activate kg-portal-child`. No plugin DB changes were made.

## Local quality checks

- `php -l` on every PHP file
- `node --check assets/js/site.js`
- `php tests/static-smoke.php`
- DDEV WordPress integration smoke (must be run by the project owner; no local DDEV instance is accessible here)
- Manual browser tests at 320/360/390/430/768/1024/1440 widths, zoom 200%, keyboard-only interactions, long text and approval revocation behavior.
