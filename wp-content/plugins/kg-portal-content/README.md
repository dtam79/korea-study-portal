# Korea Study Portal – Guide / FAQ / Policies v0.9.0

**Project milestone:** P05 guides index, P06 source-reviewed guide body, P07 category FAQs, P10 operator-approved policy pages. Based on uploaded Korean project plan v1.7, functional spec v1.3 and per-screen UI spec v1.0.

This is a **separate WordPress plugin**, not a full replacement for the already-installed `kg-portal-admin v0.8.0` or `kg-portal-child v0.5.0`. The current P08/P09 contact shell repair is untouched. `kgp-core v0.4.0+` and the existing Core source verification class are required for publication.

## Install (from `~/Projects/korea-study-portal`)

```bash
cd ~/Projects/korea-study-portal
mkdir -p ../kgp-backups
ddev export-db --file=../kgp-backups/before-content-v090.sql.gz

# Download kg-portal-content-v0.9.0.zip into ~/Downloads first.
unzip -o ~/Downloads/kg-portal-content-v0.9.0.zip -d wp-content/plugins/
ddev wp plugin activate kg-portal-content
# Activating installs ONE additive table (wp_kgpc_versions, with your WP prefix) and flushes URL rules.
ddev wp rewrite flush

ddev wp plugin list --fields=name,status,version
```

Only the new `kgpc_versions` table is created. There is no `DROP`, no imported fake data and no modifications to Core's 20 data tables. Plugin deactivation hides these pages and does not delete content, approved version history or Core records. Do NOT drop the table as part of routine rollback.

## Test (run inside DDEV project root)

```bash
ddev exec php wp-content/plugins/kg-portal-content/tests/static-smoke.php
ddev exec php wp-content/plugins/kg-portal-content/tests/offline-smoke.php

ddev wp user list --fields=ID,user_login,roles
ddev wp --user=YOUR_ADMIN_LOGIN eval-file wp-content/plugins/kg-portal-content/tests/wp-smoke.php
```

WordPress integration smoke requires existing Core/Admin and the correct editor/reviewer capabilities. This package was checked with standalone PHP syntax and mocked offline source/version logic; only your own DDEV WordPress environment can test actual source ID validation, Gutenberg editing, approval and HTML appearance.

### New public routes

- `/ko/guides/` and `/en/guides/`: P05, **six** permanent subject cards, with links only for approved content. An empty subject shows a clear empty state instead of invented statements.
- `/ko/guides/{slug}/` and `/en/guides/{slug}/`: P06, topic, title, applicability, original reference date (if stated), checked date, heading-based table of contents, official source links, next actions.
- `/ko/faq/` and `/en/faq/`: P07, five category filters (Admissions, Costs, Schedules, Living, Portal usage); keyboard-friendly `<details>` items; independent verified source per Q&A post (source required except portal usage instructions).
- `/ko/policy/` and `/en/policy/`: P10, policy index. `/ko/privacy/`, `/ko/terms/`, `/ko/about/` and equivalents (also aliases under `/policy/{slug}/`) show *only* reviewed privacy, terms and about pages.

All six topic labels are derived from the signed functional scope; no visa rules, scholarship claims or legal privacy terms have been fabricated. **No real guide articles or policy documents are auto-published**. These need real, verified public sources, translations and an operator's review.

### Six required guide topics (set `kind=guide`, `topic` appropriately)

| Topic code | Korean subject | Original sources to consult |
|---|---|---|
| `visa-preparation` | 비자 준비 (D-2, D-4) | HiKorea / Ministry of Justice |
| `part-time-work` | 아르바이트 (시간제취업) | Ministry of Justice / HiKorea |
| `arrival-settlement` | 입국 후 정착 | HiKorea / NHIS / other official agencies |
| `post-graduation-visas` | 졸업 후 진로 비자 (D-10, E-7, F-2) | HiKorea / Ministry of Justice |
| `preparation-timeline` | 유학 준비 타임라인 | Actual university admission notices / HiKorea |
| `emergency-and-scams` | 긴급 연락처와 사기 주의 | Ministry of Justice / Police |

**Do not copy example deadlines, minimum hours, financial documents or visa eligibility rules into production without original source verification.** The code will not invent a common monthly admissions timeline.

### Operator editorial workflow

1. Sign in as an account with the `kgp_edit_entries` permission. WordPress Admin → **가이드·FAQ → 새 콘텐츠**. Posts use the **WordPress block editor** (`show_in_rest=true`), so standard blocks and Kadence Blocks work where activated.
2. Add title and body (headings/tables/lists are supported). In **공식 출처·언어·적용 범위**, set `kind`, `lang`, the shared `slug`, guide topic / FAQ category, audience, verified source checked date and optional exact reference date. For required evidence types, enter the **existing, approved Core source ID(s)** (comma-separated). Optional original page/table locator is shown to reviewers and visitors. Use a **factual URL slug**, not a WordPress post permalink.
3. Save the draft; in **가이드·FAQ** top-level dashboard find it under recent drafts and select **검토 요청**. The Core source records must have valid public URLs, document names and checked dates and must pass `KGP_Evidence_Repository::valid_source`.
4. Another user with `kgp_review_entries` accesses **가이드·FAQ → 검토·게시**, opens the official original and compares the body, audience, date, exceptions and claims, checks manual verification, then approves. The author or submitting editor cannot review their own draft. Reject requires a reason. This step freezes a new approved version in `wp_kgpc_versions`.
5. Editors can continue working on the same internal post. The original approved **snapshot** remains public until the new draft is approved. Native WordPress `publish` / `future` / `private` post statuses are blocked for this internal content type so WordPress default views/REST cannot expose unapproved changes. **Only the plugin's public router reads reviewer-approved snapshots.**
6. For English content, create a new article with the **same kind and slug** and set `translation_of` to the Korean post's numeric WP post ID. The EN version is bound to the *currently approved Korean version*. A new Korean approval makes an older EN translation stale, which is **not displayed**; `/en/` displays the latest approved Korean original with a translation-pending notice instead.
7. A reviewer can withdraw approved content from **검토·게시** with a reason. If Core revokes an underlying source, the public read check hides affected content immediately even before an operator withdraws it. The live public pages send no-cache headers (`DONOTCACHEPAGE`) to limit stale responses.

**FAQ category codes:** `admission`, `cost`, `schedule`, `living`, `portal`. Categories are the *five draft categories in the v1.0 UI spec*. Final category label/copy changes should be approved by the operator.

**Policies:** `kind=policy`, slug exactly `privacy`, `terms` or `about`; approved title and body, *effective date, revision date, operator email* are required. Real legal text, privacy policy and contact details must be written, reviewed and supplied by the operator. Publishing a policy does **not** automatically switch on the separate inquiry collection: keep the A09 consent/retention settings independently controlled.

### Shared layout — previous contact header regression will not recur

This plugin **does not call Kadence `get_header()` / `get_footer()`**. It reuses the same FE classes (`kgp-shell`, `kgp-site-header`, `kgp-nav`, `kgp-language`, `kgp-footer`) and loads the **existing FE `wp-content/themes/kg-portal-child/assets/css/site.css`**. The public guide/FAQ/policy area stays at 480px max width with the same top links as the homepage. It does not touch `/ko/contact/` or `/en/contact/` routes.

### Notes & remaining required work

- This milestone implements editorial workflow and all P05/P06/P07/P10 page types, **not verified textual content**. All six real guide articles, both languages, the first FAQ set and three legal/operator policy texts must still be authored, sourced, cross-checked and approved before launch.
- Extended A09 taxonomy and scheduled official-source recheck queues, language-aware generated XML sitemap for these approved contents, full SEO/canonical across all public screens, mail infrastructure, backups/restores, visual browser acceptance, and initial 20 universities remain on the full scope. Canonical/hreflang hints and noindex for unapproved/fallback content are included here.
- Native WordPress sitemap excludes this private post type by design; don't expose private drafts to make them indexable. A future explicit approved-only content sitemap is needed before production SEO signoff.
- In a production environment, configure full page cache bypass / purge for these routes and privacy-sensitive pages; PHP-level no-cache headers can't override a badly configured reverse proxy.
- To rollback: `ddev wp plugin deactivate kg-portal-content && ddev wp rewrite flush`; restore any database from the pre-upgrade backup only if needed and with explicit permission.

Suggested commit after full local validation:

```bash
git add wp-content/plugins/kg-portal-content
git commit -m "feat(content): add source-reviewed guides FAQ and policy pages"
```
