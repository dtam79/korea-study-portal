# KG Portal Core 0.3.0 — BE-02 domain implementation

This release **implements functional PHP services** atop the previously shipped 19-table schema. No existing university records are deleted, no table is dropped, and the MariaDB `row_number` reserved-keyword fix remains included.

## Install / upgrade an existing DDEV project

From `~/Projects/korea-study-portal` on Mac:

```bash
mkdir -p ../kgp-backups
# Database first; do not skip.
ddev export-db --file=../kgp-backups/before-be02-domain.sql.gz
# Preserve your existing plugin in case a rollback is needed.
tar -czf ../kgp-backups/kg-portal-core-before-be02-domain.tar.gz -C wp-content/plugins kg-portal-core

unzip -o ~/Downloads/kg-portal-be02-domain-v0.3.0.zip -d wp-content/plugins/

ddev wp plugin is-active kg-portal-core
# Schema check; re-run install only if tables are missing.
ddev wp kgp schema status
ddev wp kgp schema install
ddev wp kgp domain setup
ddev wp kgp domain check
# Important: specify YOUR WP admin username, not the computer login username.
ddev wp --user=localadmin kgp domain smoke
```

The `smoke` command **requires a .ddev.site local URL**, runs under a WordPress administrator context, tests DB CRUD + workflow + source invalidation, and wraps all synthetic records in a transaction that is intentionally **rolled back**. It does not introduce fake university data into your working database. The data shown in smoke tests uses example.org only and must never be displayed as real official university information.

If `wp kgp domain` is not recognized, the latest files were not applied or the plugin is inactive. Run `ddev wp plugin list`, `ddev wp kgp schema status`, and inspect `ddev logs -s web`.

### Commit

```bash
git add wp-content/plugins/kg-portal-core
git commit -m "feat(be): implement verified field repositories and publication workflow"
```

## What is implemented

- `KGP_Entity_Repository::create(type, array)` — insert University, Campus, Program, Intake and Offering, verifying relations and uniqueness, with internal IDs.
- `KGP_Entity_Repository::get(type,id)` — private admin read, including an optimistic audit-based revision token.
- `KGP_Entity_Repository::update(type,id,changes,expected_revision)` — metadata update with change conflict detection; **public IDs and relationships cannot be reparented by accident**. Changing a relationship requires an explicitly created new entity.
- `KGP_Evidence_Repository::create_issuer`, `approve_issuer`, `create_source`, `verify_source`, `invalidate_source` — explicit verified official evidence steps and immediate removal of affected published values.
- `KGP_Field_Repository::save_draft` — immutable version history, field-specific or repeated-item-specific evidence, source location, datum state, optimistic lock token.
- `KGP_Field_Repository::submit`, `publish`, `reject`, `unpublish` — review states with capability checks, proof requirements, rejection audit. Modifying a published value creates a separate draft and does NOT change the visible version.
- `KGP_Public_Reader::field` and `section` — only approved, active and verifiable source-backed values; translation version binding, no unreviewed or stale translation.
- `KGP_Validator` — every `DET-01` to `DET-14` logical field (84 fields); strict money/period/year/URL/boolean validation; repeated items stored individually with `item_key`; official charge unit and currency preserved.
- `KGP_Access` — four explicit WP roles, caps, university assignment. Only authorized reviewers can publish and verify sources, and administrators cannot bypass evidence/approval tests.
- WP-CLI commands: `kgp domain setup`, `check`, `smoke`.

## Developer calls (within an authenticated WP request or WP-CLI `--user=...`)

```php
$university_id = KGP_Entity_Repository::create('university', [
    'public_id' => 'SOME-UNIQUE-ID',
    'official_name_ko' => 'University official Korean name',
    'official_name_en' => 'University official English name',
    'official_website' => 'https://example.edu/',
]);

// A manager assigns a specific editor to work on the university.
KGP_Access::assign_editor($editor_wp_user_id, $university_id);

// After related campus, program and intake records exist, create an offering.
$offering_id = KGP_Entity_Repository::create('offering', [
    'public_id' => 'SOME-UNIQUE-OFFERING-ID',
    'program_id' => $program_id,
    'intake_id' => $intake_id,
]);

// A reviewer must check real, publicly accessible institutional source material
// BEFORE calling approve_issuer / verify_source. They are not automated checks.
// $source_id comes from create_source; and its issuer must be approved.
$draft = KGP_Field_Repository::save_draft(
    'offering', $offering_id, 'DET-03', 'tuition_items',
    ['currency' => 'KRW', 'unit' => 'semester', 'amount' => '4200000', 'label' => 'Tuition'],
    [['source_id' => $source_id, 'original_locator' => 'page 17, tuition table']],
    0, 'tuition-2027'
);
KGP_Field_Repository::submit($draft['entry_id'], $draft['lock_version']);
// Reviewer: KGP_Field_Repository::publish($draft['entry_id']);
// KGP_Public_Reader::field('offering', $offering_id, 'DET-03', 'tuition_items', 'tuition-2027', 'ko');
```

**The example is illustrative; the amount, title and other example data are NOT official data. Do not publish example records.**

### Field rules

- All `repeat:*` items require a unique stable `item_key`, e.g. each tuition item or scholarship item. This enables source metadata per repeated item, rather than one broad source for an entire list.
- Cost: `{currency:'KRW', unit:'semester|month|year|one_time|program_total|term|other', amount:'123.00'}` OR `{min_amount:'...', max_amount:'...'}`, never negative and never self-computed.
- Date with original precision: `{date:'2027-04-01', precision:'day'}`, `{date:'2027-04', precision:'month'}`, or time + IANA `timezone`. Do not invent a time for date-only data.
- Source links: `source_id` plus PDF `original_locator` (page/section); optional `applies_to`, `valid_from`, `valid_to`.
- `datum_status` of `'unpublished'` or `'under_review'` can be saved with `null` but cannot be submitted as a publishable value. Verified actual `false` or `0` is preserved separately from unknown/null.
- Changing an approved value is a **new draft**, and the old published version remains accessible until a new verified version is approved.

## Validation and tests

Run static tests anywhere with PHP 8.3+, including machines without WordPress:

```bash
php wp-content/plugins/kg-portal-core/tests/schema-shape-smoke.php
php wp-content/plugins/kg-portal-core/tests/validator-smoke.php
```

Run the MariaDB workflow test only within your local DDEV WordPress container:

```bash
ddev wp --user=localadmin kgp domain smoke
```

The code package was PHP linted and passed static schema + field validation tests. The live MariaDB smoke test **must be performed on your Mac**; the build environment that generated this ZIP does not have your DDEV database.

## Not yet part of this milestone, still REQUIRED by the original project

This patch is a foundational domain layer, NOT a complete web service. Next modules must implement full admin UI, offering search and query projection, public REST/page controllers, complete validated translation management, CSV import/preview/apply, historical restore screens, inquiries, scheduled rechecks, SEO, caching orchestration and deployment/monitoring. **None has been removed from the final scope.**

A key security rule: **never register a public REST controller or template that reads raw `field_entries`, `field_versions`, or base entity rows.** Public UI code must call an approved-domain projection based on `KGP_Public_Reader`; raw underlying entity metadata is an unreviewed input until separately approved.

## Official design basis

- `한국 대학 유학 정보 포털 기능명세서 v1.3`: sections 2, 3, 4, 7, 9, 10, 11, 12.
- `한국 대학 유학 정보 포털 구축 기획안 v1.7`: official-only values, no estimates, official application redirects.
- `User Flow v1.3`: input, verification, approval, and public-display separation.

The PHP class interfaces and storage encoding are implementation designs derived from those documents, not explicitly prescribed API contracts.
