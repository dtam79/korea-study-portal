# KG Portal Operations v1.0.0 — official source review, scheduled checks, sitemap and safe caching

This is the next milestone after FE v0.5.0, Admin v0.8.0 and Content v0.9.0. **It is a new, independent WordPress plugin.** It does not overwrite the Core plugin, Kadence child theme, shared Header/Footer patch, contact templates, or content editor. No current approved university fields, source verification flags, privacy settings or operator inquiry records are changed by this installation.

Derived from the existing Korean project plan v1.7 and functional specification v1.3: **SRC-06, OPS-03/04/05, A01/A09 follow-up, T-08/T-14/T-16**. The automated system only creates review signals; **a successful HTTP response is not proof that the admissions facts are still correct.** Reviewers must inspect official documents and use the existing Core approval/invalidation workflow.

## 1. Install — local DDEV project

Run in `~/Projects/korea-study-portal`:

```bash
cd ~/Projects/korea-study-portal
mkdir -p ../kgp-backups
# Take a full database backup before adding any schema:
ddev export-db --file=../kgp-backups/before-ops-v100.sql.gz

# Download kg-portal-ops-v1.0.0.zip into Downloads, then:
unzip -o ~/Downloads/kg-portal-ops-v1.0.0.zip -d wp-content/plugins/
ddev wp plugin activate kg-portal-ops

# Install only three addon-owned tables; on a newly installed plugin this is redundant but safe:
ddev wp eval 'KGPO_Schema::install();'
ddev wp rewrite flush

ddev wp plugin list --fields=name,status,version
```

Expected tables with your site's WordPress prefix:

- `wp_kgpo_source_state` — per-official-source category, assigned reviewer, custom cadence, next check, last known metadata fingerprint;
- `wp_kgpo_source_checks` — timestamped HTTP outcome and before/after ETag, Last-Modified and limited SHA-256 sample hash; **no downloaded document body**;
- `wp_kgpo_review_tasks` — pending/in-progress/resolved human review tasks with reason, note and responsible reviewer.

No data from those tables is available to anonymous visitors. Source publication/field verification continues to run through existing Core repositories.

## 2. Validation commands

```bash
# Pure/static, no WordPress DB required:
ddev exec php wp-content/plugins/kg-portal-ops/tests/static-smoke.php
ddev exec php wp-content/plugins/kg-portal-ops/tests/offline-smoke.php
ddev exec node --check wp-content/plugins/kg-portal-ops/assets/operator.js

# WordPress Core and DB integration test (read-only):
ddev wp user list --fields=ID,user_login,roles
ddev wp --user=YOUR_ADMIN_LOGIN eval-file wp-content/plugins/kg-portal-ops/tests/wp-smoke.php
```

The integration smoke verifies the 3 addon tables, REST registration and anonymous denial, hourly schedule, SEO stage isolation. It does not contact external source URLs or modify any Core-approved record. Requires the manager/reviewer capabilities previously provisioned by Core. The current development environment used for building the ZIP does **not** have your DDEV database, so a real integration pass on your Mac is required.

## 3. New WordPress Admin pages

- `/wp-admin/admin.php?page=kgpo-operations` — review KPIs, scanner status, last run, available Core source admin link.
- `/wp-admin/admin.php?page=kgpo-sources` — source-specific category/cadence, optional assigned reviewer, manual HTTP check, 60 latest check events.
- `/wp-admin/admin.php?page=kgpo-review` — pending/in-progress/resolved change/availability queue. Human note mandatory. **Never auto-publishes or invalidates an official source.**
- `/wp-admin/admin.php?page=kgpo-seo` — manager-only scan/caching policy, optional digest recipient, approved-content counts and indexation preview, WordPress object cache purge.

Viewer requires `kgp_review_entries` or `kgp_manage_assignments`; source policy/settings changes require manager; resolving a task requires `kgp_review_entries`. The server, not merely the UI, enforces role restrictions. Every REST mutation uses WordPress cookie authentication and `X-WP-Nonce` in the administrator page.

### Source categories and default review intervals

| Category | Suggested baseline check | Notes |
| --- | ---: | --- |
| `admissions` | every 7 days | While admissions are open, the operator should confirm intake applicability and exact deadline separately |
| `fees` | 30 days | Re-review when the official intake document changes; interval is only a trigger |
| `university` | 90 days | Quarterly university information review |
| `certification` | 30 days | Also trigger manual review on actual government announcement; no invented annual release date |
| `visa` | 90 days | Also react to official revisions; no automation infers legal eligibility |
| `statistics` | 90 days | Review on actual official annual disclosure |
| `other` | 90 days | Manual category selection; not inferred from free-text descriptions |

These are **editable operations suggestions**, not an assertion that documents stay accurate for a specific duration. A newly registered source defaults to `other` until the manager classifies it. First successful download establishes a comparison baseline; it is **not** an evidence approval. If the URL becomes unavailable or a limited hash/ETag/Last-Modified changes, a reviewer task is created. Stale source status alone does not hide an approved value; invalidation remains an explicit reviewer action in Core.

## 4. Scheduling

The plugin registers an **hourly, bounded WP-Cron** event (`kgpo_scan_due`), but automatic checks default to **OFF** until the operator enables them. The per-run limit is 1–25 sources (default 15). The scanner has an execution lock and per-request network timeout. Initial state registration uses a bounded SQL query instead of modifying source approvals.

Use a real server scheduler, not visitor-triggered WP-Cron alone:

```bash
# Manual one-time status and scan in DDEV:
ddev wp kgpo status
ddev wp kgpo scan --limit=15

# Manually check a single source (real external HTTP request):
ddev wp kgpo check 42

# Example production server Cron schedule — replace WP_PATH with the actual path:
# */15 * * * * /usr/local/bin/wp --path=WP_PATH kgpo scan --limit=15 >/dev/null 2>&1
```

The server's actual `wp` executable path, WordPress working directory, email delivery, load limits and system-Cron setup must be validated in deployment. **Do not add the sample Cron line without replacing the placeholders.** `wp kgpo scan` is an explicit scan, even when the automatic toggle is off; `kgpo_scan_due` respects that toggle.

HTTP safety: only HTTP(S) URLs and allowed ports are fetched; DNS/private-IP destinations are blocked via WordPress URL validation and `wp_safe_remote_get()`; redirects are disabled; TLS is validated; retrieval uses a short timeout and sample-size cap (default 128 KiB). Only ETag, Last-Modified, response status, sampled SHA-256 and check history are stored. **This is change surveillance, not authoritative document diffing**; a PDF change outside the sampled bytes may not be detected unless ETag/Last-Modified changes. False positives are possible when official sites emit dynamic HTML, and the reviewer is expected to check the original.

## 5. SEO / staging behavior

- `/kgp-sitemap.xml`: XML sitemap on **production** only. Static public pages, genuinely published university pages whose Core public catalog provides approved offerings, and **active approved** guides and policies. The FAQ itself is an index-only page (not every FAQ as a separate URL). Does not include WordPress drafts, pending translations, unverified source values, filters, internal records or inquiry pages.
- `robots.txt`: site-wide `Disallow: /` on non-production or `*.ddev.site`; a sitemap URL is added on production. **A 404 for `/kgp-sitemap.xml` on DDEV is intentional.**
- Canonical & hreflang: FE public page families and contact pages use locale-specific canonical/alternate hints; existing Content plugin canonical/hreflang is intentionally left untouched to avoid duplication. Query-filtered and offering-variant URLs are noindex.
- Cache: conservative **strict no-cache ON** by default for approved public data, because changing an approved value must not leave a stale search/detail page. When operator-verified end-to-end invalidation exists, managers may set `strict_no_cache` OFF to allow 30-second HTTP caching. Content and inquiries still remain uncached.
- `WordPress object cache purge` clears WordPress' object cache only. It **does not purge WP-Optimize disk cache, CDN, or your hosting provider's reverse-proxy cache**. Configure those upstream layers to bypass or purge `/ko/*`, `/en/*` and sensitive URLs appropriately before production. The v1.3 spec's example 60-second public-data refresh target cannot be considered validated without this integration.

Production URL changes, canonical correctness in the SEO theme stack, real policy pages, SEO plugin duplicates and sitemap links must be checked in an actual browser before launch.

## 6. Remaining project acceptance work (not removed)

This milestone implements monitoring + a reviewer queue + basic strict cache protection and approved-only multilingual sitemap. It does **not** claim the entire project is complete. Remaining: full production CDN/WP-Optimize targeted purge wiring, source-specific **full-document** change comparison/official correction detection, certified announcement triggers, index query performance p95 tests at 2,000 offerings with 20 concurrent users, server scheduler and observability, complete 20 verified universities + bilingual content, production-grade deployment/backup restore, server email and privacy validation, performance, accessibility and SEO acceptance. Initial cadence rules must be approved by the operator.

## 7. Rollback

```bash
ddev wp plugin deactivate kg-portal-ops
ddev wp rewrite flush
```

Deactivation cancels the plugin's WP-Cron hook; it does **not** delete the addon monitoring tables or any Core data. The project continues to render its shared Header/Footer, approved university pages, guides and contact page exactly as before this addon. For a complete rollback, restore the original DB backup only after comparing any concurrent edits.

Suggested commit after DDEV smoke tests:

```bash
git add wp-content/plugins/kg-portal-ops
git commit -m "feat(ops): add official source monitor review queue and safe SEO"
```
