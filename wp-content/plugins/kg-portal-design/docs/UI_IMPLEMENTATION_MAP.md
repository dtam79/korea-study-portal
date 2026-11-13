# UI implementation map — v2.1.1

This map describes where the new design styles apply. The underlying markup is produced by independent existing plugins; no page content or backend endpoint has been replaced.

| Screen | Source template family | Updated hierarchy / component rules |
| --- | --- | --- |
| P01 Home | kg-portal-child | shared shell, 36→28 H1, primary search, section rhythm, cards |
| P02 University search | kg-portal-child | inline filters, search input, results card, state, pagination, result grid |
| P03 University details | kg-portal-child | title/identity → offering group → metadata → official sources → action, accordion/table |
| P04 Admissions | kg-portal-child | dates, responsive cards/tables, status/official action |
| P05–P06 Guides | kg-portal-content | 6 topic cards (3/2/1 columns), introduction, content max 860, facts, sources |
| P07 FAQ | kg-portal-content | category selection, 16px question/answer, expandable status, source cards |
| P08–P09 Contact | kg-portal-admin + earlier shared-shell fix | common nav/footer, recipient tabs, 48px labeled fields, privacy notice, confirmation |
| P10 Policy | kg-portal-content | readable long-form content, evidence and shared footer |
| A01–A07 core operations | kg-portal-admin | header, KPI, nav, form grids, lists, structured values and review panels |
| A08–A09 inquiries/settings | kg-portal-admin | 2-col desktop settings, private inquiry facts, history and notes |
| Guide/FAQ author/review | kg-portal-content | Gutenberg meta boxes organized into semantic sections, content review tables |
| Source monitoring | kg-portal-ops | common operator controls, status cards, source/review tables |

**Not changed:** post types, custom metadata names, underlying source/translation evidence, permission checks, form submit methods, CSV import logic, email handling, cron behavior, published version checks or backend schema.

## Original 34-image mockup observations

- Public screens 01–16 use white surfaces, muted blue backgrounds, blue priority CTAs, 1-column mobile reading order, and strong H1 vs H2 visual separation.
- Screens 02/06 use explicit search, applied-filter chips, status badges and result card rows; neither unverified university imagery nor inferred pricing is permitted.
- Screen 07 makes scope and source of each university field visible in the detail page.
- Screens 10/12 show native FAQ interaction in discrete accordion cards; 11/13 place the contact form in a consistent page card with labels above inputs.
- Screens 17–34 use desktop operator navigation with a strong single title, data table/KPI group, separated source/value panels and a primary review/submit action.
- The mockup contains example text/data only. No such example is imported to production by this plugin.

## Notes on QA limitations

This code was verified on the source snippets and original mockup available to ChatGPT. It cannot directly inspect the user's live `~/Projects/korea-study-portal` directory or logged-in WordPress UI. The `kg-portal-child`/`kg-portal-admin` versions previously shipped are known from past work, but their exact CURRENT local file bytes were not available in this environment. This release is therefore compatibility-scoped, makes no destructive edits, and ships with clear rollback commands. Run visual QA at 360, 768, 1024 and 1440px before considering the rollout complete.

## Important correction (v2.1.1)

The original design documents v1.2 require **public <=480px at every viewport width**, with one-column cards and inline filters; desktop-width public grids introduced earlier were incorrect and are now removed. The 1280/1440px workspace guidance applies to **administration only**. See `V2_1_DESIGN_ALIGNMENT.md`.
