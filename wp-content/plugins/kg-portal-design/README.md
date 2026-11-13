# KG Portal Design System v2.1.2

**UI/UX only.** This is a complete replacement for the installed `kg-portal-design` plugin **v2.0.0**. It retains all relationship lookup, native filter values/query, search, editor, WordPress REST routing and save/review logic of the uploaded v2.0.0 source. It does not replace or modify Core, Admin, Content, Ops, Child Theme, database schema or approved publications.

## Grounded references

This release was compared with the uploaded **34 visual mockups** (public screens 01–16, operator screens 17–34), and the 7 accompanying documents, including **Design Concept & Brand Guide v1.2**, **Detailed UI Specification v1.2**, **Screen Specification v1.2**, and **Design Handover v1.2**.

**Critical corrected requirement:** The *public portal* keeps the **same centered, single-column mobile layout at every viewport width**, with an **outer maximum width of 480px**. The previous design v2.0.0 incorrectly introduced 1200px public content and multi-column cards above 1024px. That is explicitly contrary to the reference v1.2 documentation. The **administrator** keeps a separate wide 1280/1440px workspace. No public width cap applies to admin or login.

### Shared design tokens

| Component | v2.1.2 | Reference |
| --- | --- | --- |
| Public outer width | max 480px everywhere | BG v1.2 / DH H14 |
| Public grid | 1 column everywhere; 16px card spacing | UI P01–P10 |
| Body side padding | 16px (14px at 320–359px) | BG v1.2 |
| Heading 1 / 2 / 3 | 28px / 24px / 20px | BG v1.2 |
| Body / support / metadata | 16/26, 14/22, 13/20px | BG v1.2 |
| Card / field / button corners | 12 / 8 / 8px | BG v1.2 |
| Input and tap heights | ≥48px / ≥44px | BG v1.2 |
| Section and card spacing | 32 / 16px | BG v1.2 |
| Input arrow | 18px icon, right inset 14px | Mockup / alignment improvement |
| Admin max width | 1440px | A01–A09 references |

Colors remain `#1D4ED8`, `#1E40AF`, `#0F172A`, `#475569`, `#F8FAFC`, white, `#64748B` input border, `#CBD5E1` divider.

## Changes by screen

| Scope | Presentation-only improvement | Preserved |
| --- | --- | --- |
| P01 Home | single-column hero/search/shortcut cards, consistent heading and label sizes | search destinations/quick access |
| P02 University search | compact inline filters; select arrow inset; Boolean proxy/check layout; one-column result cards and count | original GET values, Apply / Reset, Back and URL state |
| P03 University details | compact dependent sections, facts, source evidence and local table scrolling | approved fields, linked source IDs, official actions |
| P04 Admissions | native disclosure height 48px, filter group spacing, count/results, 14px empty state | date precision, sorting, current filter behavior |
| P05–P06 Guides | six-topic list one column, 28/24/20 heading hierarchy, TOC/evidence/scope | approved article content, source rules |
| P07 FAQ | understated accordion, consistent question and answer density | native `<details>` keyboard and open state |
| P08–P09 Contact | balanced tabs, labeled inputs, privacy block and receipt | consent, submission, nonce, email, dedupe, storage |
| P10 Policy / S01 | long-form reading, facts, errors and metadata styling | unpublished/noindex handling and policy text |
| A01–A09 Admin | desktop left navigation, disciplined typography/cards/forms/tables/KPI status/sources | all REST permissions, saving, translation, CSV, history, review, inquiry settings |

**Not changed:** `assets/admin.js`, `assets/public.js`, `assets/relations.js`, `includes/class-lookup.php` — these are **byte-identical** to the uploaded v2.0.0. `kg-portal-design.php` changes only its two version strings from 2.0.0 to 2.1.0. The presentation-only `assets/filter-controls.js` adapter has intentionally been updated to show exactly two choices per Boolean filter. Changed files are limited to this UI adapter, presentation styles, preview fixtures and documentation/tests.

## Installation

Run in your DDEV project root, not in the downloads folder.

```bash
cd ~/Projects/korea-study-portal
mkdir -p ../kgp-backups

# Save your installed 2.0.0 design plugin; no DB backup is required by this UI-only change.
tar -czf ../kgp-backups/kg-portal-design-before-v210.tar.gz -C wp-content/plugins kg-portal-design

# Download kg-portal-design-v2.1.2.zip into ~/Downloads, then overlay the design plugin.
unzip -o ~/Downloads/kg-portal-design-v2.1.2.zip -d wp-content/plugins/

ddev wp plugin activate kg-portal-design
ddev wp cache flush
```

Then hard refresh your browser (`Cmd+Shift+R`). If the old styles remain, verify that page caches and CDN/optimization CSS aren't serving the previous stylesheet. The plugin uses the new `2.1.0` version token for enqueued assets.

## Offline checks (no WordPress or DB needed)

```bash
python3 wp-content/plugins/kg-portal-design/tests/logic-integrity.py
python3 wp-content/plugins/kg-portal-design/tests/mockup-visual-contract.py
python3 wp-content/plugins/kg-portal-design/tests/static-smoke.py
python3 wp-content/plugins/kg-portal-design/tests/precision-smoke.py
python3 wp-content/plugins/kg-portal-design/tests/filter-ux-smoke.py

ddev exec php -l wp-content/plugins/kg-portal-design/kg-portal-design.php
ddev exec php -l wp-content/plugins/kg-portal-design/includes/class-lookup.php
```

A real WordPress integration lookup test from v2.0.0 is retained:

```bash
ddev wp eval-file wp-content/plugins/kg-portal-design/tests/lookup-integration.php --user=ADMIN_LOGIN
```

The test above is read-only; replace `ADMIN_LOGIN` with a valid operator admin login.

## Visual preview and browser QA

- `preview/public-preview.html`: synthetic public components (single-column search/filter, admission card, empty state, FAQ, contact) using **the actual v2.1.2 CSS**. No real data.
- `preview/admin-preview.html`: existing interactive synthetic admin preview loaded with the latest admin CSS. Does not save data.
- `tests/qa-admissions.html`: a compact standalone admissions fixture.

**Real-site acceptance still required:** use 320, 360, 390, 430, 480, 768, 1024 and 1440px viewport widths; public `.kgp-shell` must never exceed 480px. Verify long Korean/English strings, keyboard focus, 200% zoom, filter Apply/Reset/Back, document source disclosure, translation/CSV administration and contact consent. Public should never switch to a desktop card grid, and private admin should not be confined to 480px.

CSS parsing, PHP/JS lint and logic file hash checks were executed in the sandbox. A Chromium screenshot run did not complete in this environment; **live DDEV visual verification is not claimed**.

## Rollback

```bash
cd ~/Projects/korea-study-portal
ddev wp plugin deactivate kg-portal-design
ddev wp cache flush
```

Or restore the archived plugin directory and reactivate. No database schema or source approval data is modified by this upgrade.

Suggested commit:

```bash
git add wp-content/plugins/kg-portal-design
git commit -m "style(portal): align v2 design with v1.2 mockups without logic changes"
```


## v2.1.2 — Two choices, no negative-only mode

The certification filter now displays **인증 / 전체 보기**. The degree-language filter displays **100% 영어 학위 / 전체 보기**. The second button means *both yes and no*, not *no only*. English UI labels are **Accredited / View all** and **100% English degree / View all**.

Each group uses two accessible `aria-pressed` buttons within a labelled group. The pre-existing native `<select>` stays present with the same `name`, `value`s, form submission and `input`/`change` events. No HTTP API or backend search code changed. Only its visual adapter changed; if a stored legacy `no` value is encountered, it is normalized to `all` on display because the negative-only mode was intentionally removed from the UI. Test form reset and browser back/forward behavior on a live DDEV page after installation.


## v2.1.2 — Filter reset and active-criteria hierarchy

- **필터 초기화 / Clear filters:** clears all university/admissions criteria, including accreditation, degree language, region, program, fee bounds, and sorting, then navigates through the **existing route** with only the current keyword query (`q`/`search`/`keyword`/`s`/`query`/`term`, etc.). It does not delete the typed keyword or modify the Core search API. If no keyword exists, navigates to the unfiltered listing.
- The action intercepts an existing reset control; if there isn't one, the UI adapter adds one to the filter form. Existing form `name` attributes, native select values and data-fetch handlers are not modified. When the page's search field has a recognized query name, the latest typed value takes priority over an old query in the URL. This avoids accidentally applying a stale filter while resetting.
- **Live selection indicator:** the native filter disclosure shows `2개 적용 · 인증 · 지역: 서울` as a count and up to two blue/white chips (plus `+N` for additional criteria). This remains visible when the disclosure is closed and updates on input/change, reset and browser Back.
- **Selected label hierarchy:** active field labels become 14px, semibold/bold, primary blue (`#1D4ED8`), while inactive labels remain 13px neutral. Active control borders and affirmative segmented actions use gentle blue highlights; **전체 보기** remains neutral because it means unfiltered.
- The public shell remains **max-width 480px on all viewports**, and admin layout is untouched.

### Local tests

```bash
node wp-content/plugins/kg-portal-design/tests/boolean-dom-smoke.cjs
python3 wp-content/plugins/kg-portal-design/tests/filter-reset-smoke.py
python3 wp-content/plugins/kg-portal-design/tests/logic-integrity.py
```

### Browser verification

On `/ko/universities/` enter a search keyword, select `인증` plus a region or tuition bound, and click `필터 초기화`. The resulting address should contain only the keyword parameter (such as `?q=KAIST`), with all advanced filter values cleared. Check the same behavior on `/ko/admissions/` and `/en/...` and browser Back. If your WordPress theme uses a nonstandard keyword parameter, adjust `keywordKeys` in `assets/filter-controls.js` to match the **existing** parameter name; do not modify REST logic.
