# KG Portal Admin v0.7.0

**Next implementation step: A01–A07 WordPress operator screens.** This is an **independent plugin** that uses the existing `kg-portal-core` v0.4.0+ service and REST routes. Do not replace the core plugin or the Kadence child theme. No table creation, table deletion, fake university data, or changes to published values occur during installation.

## 1. Install in your existing DDEV project (Mac)

Run inside `~/Projects/korea-study-portal`:

```bash
mkdir -p ../kgp-backups
ddev export-db --file=../kgp-backups/before-admin-v060.sql.gz

# If an older addon already exists, preserve it first.
if [ -d wp-content/plugins/kg-portal-admin ]; then
  tar -czf ../kgp-backups/kg-portal-admin-before-v060.tar.gz -C wp-content/plugins kg-portal-admin
fi

unzip -o ~/Downloads/kg-portal-admin-v0.6.0.zip -d wp-content/plugins/
ddev wp plugin activate kg-portal-admin

ddev wp plugin list --fields=name,status,version
ddev wp kgp api check
```

Make sure `kg-portal-core` **v0.4.0+** and `kg-portal-child` **v0.5.0** are still active. WordPress roles and capabilities must have been installed by the previous step (`ddev wp kgp domain setup`).

## 2. Verify

```bash
ddev exec php wp-content/plugins/kg-portal-admin/tests/static-smoke.php
ddev exec node --check wp-content/plugins/kg-portal-admin/assets/admin.js

# Replace YOUR_ADMIN_LOGIN with your actual WordPress administrator login.
ddev wp user list --fields=ID,user_login,roles
ddev wp --user=YOUR_ADMIN_LOGIN eval-file wp-content/plugins/kg-portal-admin/tests/wp-smoke.php
```

Open the local WordPress admin page:

`https://korea-study-portal.ddev.site/wp-admin/admin.php?page=kgp-operator`

A new **Study Portal** menu should appear in the WordPress sidebar. The menu is visible only to the Core roles that can access it.

**Expected WordPress smoke result:** `Success: Operator admin integration smoke passed. No database changes.` The test only performs authenticated READ calls, confirms that anonymous users are denied and checks all 14 DET field sections.

## 3. Operator workflow

1. **Dashboard (A01):** Review pending counts, Core health, and assign an information editor (portal manager only).
2. **University data (A02/A03):** Add `university → campus → program`, add an `intake`, then create a matching `offering` using the program and intake IDs. These records are *private* until reviewed. Edit permitted metadata with an optimistic revision token; core identity or relationships cannot be silently changed.
3. **Official sources (A03/A04):** Add an approved source issuer, register a document with public original URL, page/table location, applicable intake and dates. The authorized source reviewer must open the actual document and record verification. An issuer's approval **does not** approve its individual documents.
4. **Identity submission (A04):** Open an entity, attach the source ID and original location, submit the frozen snapshot. A reviewer can view the exact submitted snapshot and official document, then approve or reject it. University, campus, program, intake and offering each need their own approved identity for their public combination to be visible.
5. **Field drafts (A03):** Select entity type + its internal ID. Select a DET section and field from the backend catalog (all 14 sections, 84 logical field definitions). Fill in the typed value, one stable item key per repeated item, and one or more source references with location and applicability window. Save as a new draft. `under_review` / `unpublished` values store `null` and cannot be published.
6. **Review & publish (A04):** Review identity and field queues. Inspect evidence, verify official values/periods, approve or reject with a reason. When a source is invalidated, Core immediately suppresses affected public fields. Use the "Unpublish" action to take approved metadata or fields off public pages.

Keep examples and test values out of production. **Do not accept a draft because its URL merely exists.** Source reviewers must actually compare the official original with the values and applicability.

## 4. Permission boundaries

| Core role | Available work |
| --- | --- |
| `kgp_information_editor` | View assigned universities, create/edit their private records, add sources, draft fields and submit identity/field drafts |
| `kgp_reviewer` | View draft/identity queues, review sources, publish, reject, unpublish, view history |
| `kgp_portal_manager` | Create university identity records, approve source issuers, assign editors, view entity index |
| `administrator` | WordPress administrative functions plus KGP role capabilities already granted by Core; must still satisfy Core's source/publish checks |

Permissions are checked **on the server** by the Core REST handlers and this plugin's private read endpoints. Hiding an action in the interface is never the security boundary.

WordPress cookie-authenticated API calls include the `X-WP-Nonce` token from the current user's admin session. Don't expose the token in public theme code or copy administrator REST URLs into a public HTML page.

## 5. What this package adds

```
wp-content/plugins/kg-portal-admin/
  kg-portal-admin.php
  inc/class-admin.php             # WordPress admin menu, asset enqueue, role checks
  inc/class-rest.php              # Private: catalog, scoped entity search, review queue,
                                  # private source evidence, field history, health
  assets/admin.js                 # A01–A04 admin UI, existing Core REST writes
  assets/admin.css                # Scoped responsive admin styles
  tests/static-smoke.php
  tests/js-smoke.cjs
  tests/wp-smoke.php
  docs/operator-workflow.md
```

The plugin adds **no database tables** and **no public REST endpoint**. Its read-only endpoints are under `/wp-json/kgp-operator/v1/`, authenticated and permission checked. Mutations still use Core `/wp-json/kgp/v1/admin/...` endpoints and therefore retain server-side validation and audit logging.

## 6. Known boundaries (still REQUIRED by original spec)

This milestone targets **A01–A04** and offers basic version/source inspection. The following are **not** claimed complete and remain required for the full product: A05 full translation draft/review, A06 CSV import/preview/error management, A07 version-diff/history/restore UI, A08 inquiry workflow and retention policy, A09 comprehensive classification and system settings, six verified guide articles, FAQ, privacy/contact public screens, performance indexing, SEO, deployment/backups, and full acceptance testing. There is also a pending server-side hardening task to enforce explicit reviewer-vs-author separation where organization policy requires it; a checkbox in this UI is not a substitute for backend enforcement.

**Important compatibility note:** This GUI targets the API methods, field catalog and table names from the previously delivered `kg-portal-core v0.4.0`. Custom changes to those route names or schemas may require reconciliation before installation.

## 7. Rollback

Disabling this addon leaves Core data unchanged:

```bash
ddev wp plugin deactivate kg-portal-admin
```

If necessary, restore the previous addon backup. Do not drop the Core tables. No data migration occurs when this addon is installed or deactivated.

After successful verification:

```bash
git add wp-content/plugins/kg-portal-admin
git commit -m "feat(admin): add university data and evidence review console"
```


---

# v0.7.0 update: A05 translations, A06 CSV import, A07 history & restore

This is a **full overlay** of the v0.6.0 `kg-portal-admin` plugin folder. It **does not replace** `kg-portal-core` v0.4.0 or the v0.5.0 child theme. It adds three new addon-owned tables: `wp_kgpa_translation_drafts`, `wp_kgpa_csv_batches`, `wp_kgpa_csv_rows` (the prefix may differ). No existing Core table is deleted; original public data remains unchanged upon installation. This is an A05–A07 implementation milestone, **not** an assertion that all project requirements are finished.

## Safe upgrade (from ~/Projects/korea-study-portal)

```bash
mkdir -p ../kgp-backups
ddev export-db --file=../kgp-backups/before-admin-v070.sql.gz
tar -czf ../kgp-backups/kg-portal-admin-before-v070.tar.gz -C wp-content/plugins kg-portal-admin
unzip -o ~/Downloads/kg-portal-admin-v0.7.0.zip -d wp-content/plugins/

# File replacement does not retrigger the WordPress activation hook: run the new additive migration manually.
ddev wp eval 'KGP_Operator_Workflow_Schema::install();'

ddev exec php wp-content/plugins/kg-portal-admin/tests/workflow-static-smoke.php
ddev exec php wp-content/plugins/kg-portal-admin/tests/csv-parser-smoke.php
# Select a real admin login rather than your macOS login:
ddev wp user list --fields=ID,user_login,roles
ddev wp --user=YOUR_ADMIN_LOGIN eval-file wp-content/plugins/kg-portal-admin/tests/workflow-wp-smoke.php
```

WordPress Admin → Study Portal now has the additional pages **Translations**, **CSV imports**, **History & restore**. The admin UI requires JavaScript. If the menu is stale, reload with a hard refresh and clear any site/admin caches.

### A05 — source-version-bound English translation

- From **Translations**, enter the Core `field_entry` ID (available in A03) and choose an immutable field version.
- Translation values are limited to descriptive text. Number, KRW, date, URL, identifier, unit and other shared facts must remain byte-for-byte identical.
- An editor saves a versioned draft and submits for review; a **different reviewer** must inspect the source version and source document then approve or reject. Approval writes into the **existing Core translations table**, which `KGP_Public_Reader` uses.
- Approval is blocked unless the source field version is *currently approved and published* and its linked official evidence remains valid. The new Core field version automatically makes the old translated description inaccessible (the reader queries by `field_version_id`).
- If Core's existing translation table differs from the expected columns (`field_version_id, locale, translated_json, status`) an explicit error is emitted instead of unsafe writes. This version implements **English translation of versioned text fields**; broader editorial translation of static UI text or policy documents remains in their designated stages.

### A06 — CSV import with row validation and staged write

- **Download blank CSV schema** from the page, then fill in official records. UTF-8 CSV, maximum **2 MiB** and **500 nonempty rows** per batch.
- Supported `entity_type`: `issuer`, `university`, `campus`, `program`, `intake`, `offering`, `source`, `field`.
- Identifiers are public IDs (`university_ref`, `campus_ref`, `program_ref`, `intake_ref`, `issuer_ref`, `source_ref`, etc.), *not database row names*. Parent relationships can occur in the same CSV and are resolved in dependency order at apply time.
- For `field` rows, set `target_type`, `target_public_id`, `section_code`, `field_key`, optional stable repeated `item_key`, `value_json`, `source_ref`, and `source_locator`. `value_json` must be one JSON value of the type defined in `KGP_Field_Catalog` (for a localized string, use a JSON-encoded string, not a bare CSV text value).
- Preview stores **only staging records**, not universities, public fields or approvals. It shows per-row `create/update/keep/error`, current values, input values and errors.
- Check the normal/ready rows, then explicitly click Apply. An erroneous, unselected, duplicate or already applied row is not applied. Each selected row is independently transactional and its result/auditor is recorded. Changed entities remain private until approved identity snapshots are re-published. Field rows become **new private drafts**, not approved public values.
- Existing official sources/issuers are immutable by their public ID; upload a new source ID for a changed official document and verify it separately. Referential and scope checks are performed again when applying. Relations and public IDs cannot be silently reparented.
- Reuploading byte-identical files by the same user returns the existing batch; duplicate selections cannot be applied again. A repeated field already matching the source and value is retained without a new draft.

### A07 — field and identity history/restoration

- Enter a Core `field_entry` ID to inspect the immutable version list; compare selected versions and original source links.
- `Restore as new draft` invokes Core `KGP_Field_Repository::save_draft` and preserves the historical source links, but does **not** touch the approved version. Conflicts and unavailable relationships are reported.
- An entity type + internal entity ID loads the Core audit timeline. Historical identity publications can be restored to a private `draft_json` using the original snapshot **only if its immutable IDs/relationships still match**. Then request review. A reviewer must approve through A04.
- Author, event type, event time and before/after JSON are included. Pagination is 30 audit events/page in the API; the current UI shows the first page and the API supports additional pages.

### New code files

- `inc/workflow/class-schema.php`: addon migrations (3 extra tables)
- `inc/workflow/class-translations.php`: English version-bound translation lifecycle
- `inc/workflow/class-csv.php`: UTF-8 CSV preview, apply, dedupe and audit
- `inc/workflow/class-history.php`: audit, compare, field/identity draft restoration
- `inc/workflow/class-routes.php`: private, permission-checked REST endpoints
- `assets/workflows.js`: A05/A06/A07 admin UI
- `tests/workflow-static-smoke.php`, `tests/csv-parser-smoke.php`, `tests/workflow-wp-smoke.php`
- `samples/kgp-import-template.csv`: blank comma-separated import schema

## Integration-test status and limits

PHP syntax and offline parser/contract tests can run outside WordPress. The `workflow-wp-smoke.php` script requires the user's DDEV database and must be run locally; it tests private REST routing, 3 tables, anonymous access rejection and a CSV preview with its staging writes **rolled back**. Full browser interaction, email/FAQ, indexed search, scheduled rechecks, inquiry retention, license checks, data entry for actual universities and comprehensive deployment QA are NOT included in this milestone and must still be completed.

**Known implementation constraints:** CSV stages references by public ID; rows depending on missing or nonselected parents will fail with a row-specific explanation and do not auto-invent parents. Import supports public identity metadata and evidence-backed fields, but **never** mass-approves institutions or field content. Similarly, author/reviewer separation is enforced for new translations but the earlier Core field-approval API retains its existing authorization policy until separately hardened.

Rollback: deactivate this addon, restore the previous addon files and (if you want the staging records removed) restore the database backup. Do not manually drop or truncate Core production tables.

Suggested Git commit after live smoke passes:

```bash
git add wp-content/plugins/kg-portal-admin
git commit -m "feat(admin): add translation, csv import and history restore workflows"
```

---

# v0.8.0 update — A08 Inquiries, A09 operating settings, P08/P09 contact/confirmation

**Full plugin overlay of v0.7.0.** It retains A01–A07 and does not change Core 0.4.0 or Kadence FE 0.5.0. It adds one addon-owned audit table (`wp_kgpa_inquiry_deletion_audit`, prefix may vary), plus WordPress options and an hourly/daily scheduled job. The existing Core `wp_kgp_inquiries` and `wp_kgp_inquiry_events` tables continue to store restricted inquiry contents. There is **no schema deletion** or sample private-data insertion during installation.

## Installation — from the DDEV project root on Mac

```sh
cd ~/Projects/korea-study-portal
mkdir -p ../kgp-backups

ddev export-db --file=../kgp-backups/before-admin-v080.sql.gz
tar -czf ../kgp-backups/kg-portal-admin-before-v080.tar.gz -C wp-content/plugins kg-portal-admin

# Download kg-portal-admin-v0.8.0.zip to ~/Downloads, then overlay it:
unzip -o ~/Downloads/kg-portal-admin-v0.8.0.zip -d wp-content/plugins/

# Updating files alone does not re-run the activation hook:
ddev wp eval 'KGP_Operator_Inquiry_Schema::install();'
ddev wp rewrite flush

ddev wp plugin list --fields=name,status,version
ddev wp kgp api check
ddev wp user list --fields=ID,user_login,roles
```

### Tests

```sh
ddev exec php wp-content/plugins/kg-portal-admin/tests/inquiry-static-smoke.php
ddev exec php wp-content/plugins/kg-portal-admin/tests/inquiry-unit-smoke.php
ddev exec node --check wp-content/plugins/kg-portal-admin/assets/inquiries.js
ddev exec node --check wp-content/plugins/kg-portal-admin/assets/contact.js

ddev wp --user=YOUR_ADMIN_LOGIN eval-file wp-content/plugins/kg-portal-admin/tests/inquiry-wp-smoke.php
```

The WordPress smoke test runs only under a local `*.ddev.site` URL. It verifies role restrictions, consent/retention, real database inquiry creation, a deliberately failed test email (via `pre_wp_mail`), no duplicate records with the same form token, inquiry status/notes, physical deletion and its PII-free audit. All inserted test records are rolled back and your previous settings are restored. It **does not send an actual email**.

## Public pages

- `/ko/contact/` and `/en/contact/` — P08: operator contact form, separate university-direct-contact mode
- `/ko/contact/complete/?receipt=KGP-...` and `/en/contact/complete/...` — P09: receipt-only confirmation (no email or question text in URL)
- Public REST `GET /wp-json/kgpa/v1/offerings?university=PUBLIC_ID&lang=ko` — approved offerings for the optional university/program picker

The public form's user inputs are email (required, ≤254 chars), consultation language (ko/en), message (required, 10–2000 chars), mandatory **unchecked** consent checkbox, optional university/offering (only existing, published university + related offering), and optional preferred intake (≤100 chars). No file uploads are accepted. The form displays the exact purpose, notice, deletion request contact, policy version, and retention period the operator saved. University inquiries show **verified external official contact links** when available; otherwise a clearly labeled general university website link and the fact that no contact endpoint is verified. The portal does **not** forward user messages to a university.

The existing Kadence theme route `/ko/contact/` was a placeholder; this addon now intentionally serves the functional inquiry page **without altering the Kadence parent theme or the child theme's source files**. It uses its own matching mobile-first layout and runs with or without the child theme loaded.

## Admin operations

- WordPress → **Study Portal → Inquiries (A08)**: search by receipt/email, status filters (`new`, `in_progress`, `completed`), assignment to an inquiry-manager account, private details, internal notes, event history, formula-safe CSV export **excluding message and notes**, and a confirmed permanent deletion.
- WordPress → **Study Portal → Operating settings (A09)**: explicit operator-reviewed bilingual purpose and privacy notice, retention days, policy version, notification mailbox, deletion-contact mailbox, per-IP throttling and enable switch.

Only `kgp_manage_inquiries` users can view/edit/export/erase messages and notes. Only `kgp_manage_assignments` managers can change settings. No unauthenticated endpoint can list inquiries. The new public contact form writes to Core's existing **private** `inquiries` table; no inquiry content is embedded in published university data, Core public search, or public REST responses.

### Critical: do not start collecting real personal data before policy approval

**Collection is OFF by default.** The operator must explicitly supply and review the full privacy notice and retention period, valid mailbox for notification/deletion requests, then enable `intake_enabled`. These are organizational/legal decisions, **not supplied by this code**. Do not publish placeholder notices or placeholder inboxes. Complete privacy/legal review, backup-retention policy and real email transport setup before enabling on production.

The public form uses WordPress nonce and a per-render transient challenge, a honeypot, hourly salted-IP transient rate-limit, and an opaque unique receipt identifier. Duplicate submission of the same form token returns the existing receipt; notification is sent only after inquiry insertion. If `wp_mail()` fails, the inquiry stays saved and its `email_delivery_status` is `failed`, with an event entry. The email notification contains only an opaque receipt and a secure admin URL, **not** the user's email or message.

### Retention / deletion

`kgpa_inquiry_retention_cleanup` is registered as a daily WP-Cron event. Each run deletes up to 100 expired inquiries **and their private events/notes** in a DB transaction; it writes a deletion audit with a one-way keyed receipt hash, actor ID (if manual), reason and UTC deletion time, but **not the original receipt, email or message**. The next daily run handles any remaining backlog. A manual operator deletion immediately erases the same data. Individual inquiries keep their `delete_after` value as set at submission time.

**Important production requirement:** Standard WP-Cron is triggered by visits and does not guarantee execution on a quiet site. Configure a real external scheduler (WP-CLI `wp cron event run --due-now` or server/system Cron) and monitor its successful execution. In DDEV, inspect with:

```sh
ddev wp cron event list | grep kgpa_inquiry_retention_cleanup
# Runs due cron tasks (will delete real expired inquiries):
# ddev wp cron event run kgpa_inquiry_retention_cleanup
```

Deletion is performed in the live DB; existing offsite backups may retain older data until the separately approved backup expiry cycle. Set a compliant backups/deletion policy before going live.

## Private REST routes (all under `/wp-json/kgp-operator/v1/`)

| Method | Endpoint | Capability |
|---|---|---|
| GET | `inquiries?status=&q=&page=&per_page=` | `kgp_manage_inquiries` |
| GET | `inquiries/{id}` | `kgp_manage_inquiries` |
| POST | `inquiries/{id}/update` | `kgp_manage_inquiries` |
| POST | `inquiries/{id}/note` | `kgp_manage_inquiries` |
| POST | `inquiries/{id}/erase` with `{ "confirm": "DELETE" }` | `kgp_manage_inquiries` |
| GET, POST | `inquiry-settings` | `kgp_manage_assignments` |

CSV export is served through a separate authenticated `admin-post.php?action=kgpa_export_inquiries` route protected by a WordPress nonce and inquiry-manager capability. Never copy this admin URL/token into publicly readable pages.

## Remaining final scope (unchanged, not removed)

A09's broader classification/recheck/system administration, guide/FAQ verified article content P05–P07 and policy page P10, source refresh scheduling, search indexing/caching and SEO, email delivery monitoring/backup configuration and real production restore validation are **still required**. This phase implements the inquiry workflow and its essential operational settings; it does not claim full system completion.

## Production verification checklist

1. Enter and approve **real**, bilingual legal privacy notice and retention days. Keep disabled until reviewed.
2. Send an operator inquiry using the public Korean and English forms; verify the receipt and that no double-post occurs.
3. Use DDEV Mailpit (`ddev mailpit`) to verify notifications. Simulate an SMTP failure to confirm the stored record remains saved and the status changes to `failed`.
4. Sign in as the `kgp_inquiry_manager` and verify status assignment, notes, and export. Sign out and confirm private REST access is rejected.
5. Confirm university inquiry mode does not forward or collect messages and opens only reviewed contact information.
6. Test deletion on a test inquiry and review the deletion audit. Configure a server Cron and verify expiration independent of page views.
7. Re-run A01–A07 tests, then test 360px/desktop and keyboard-only public contact screens.

After tests pass:

```sh
git add wp-content/plugins/kg-portal-admin
git commit -m "feat(admin): implement inquiry workflow and retention settings"
```
