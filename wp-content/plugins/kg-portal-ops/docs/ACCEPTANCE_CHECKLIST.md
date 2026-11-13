# Operational acceptance checks — KGP v1.0.0

- [ ] Plugin active; Core/Admin/Content/Child Theme still active.
- [ ] 3 KGPO tables were created, without migrations of Core tables.
- [ ] Reviewer and manager can open the right menus; anonymous cannot load private REST routes.
- [ ] Source with first successful HTTP check reports `baseline`, never `approved`.
- [ ] A changed ETag/document sample creates exactly one active review task; concurrent scans do not create active duplicate tasks.
- [ ] Broken URL, disallowed private IP and expired Core source are flagged, never fetched unsafely or automatically approved.
- [ ] Changing category/cadence adjusts future check date and does not postpone an overdue item.
- [ ] Reviewer can record evidence, move task to in progress, then mark grounded resolution; Core source invalidation remains a separate action.
- [ ] Automatic scheduled checks remain off until operator chooses to enable them.
- [ ] Cron can run without website visitors; failure logging and lock recovery verified.
- [ ] On DDEV: `robots.txt` disallows indexing; `/kgp-sitemap.xml` returns HTTP 404.
- [ ] On production: sitemap includes only Core-published university records with visible offerings, and actively approved Content posts (not drafts, pending translations or inquiry records).
- [ ] Filter combinations/offerings are noindex; approved FE pages have correct locale canonical and hreflang; no duplicate SEO-plugin tags.
- [ ] Home, search, university details, guides, FAQ, policy, inquiry and completion pages retain same Header/Footer patch and 480px design.
- [ ] If strict no-cache is disabled, public data still changes within agreed freshness goal after approvals or invalidations; test WP-Optimize and CDN separately.
- [ ] Full source doc comparisons, backup restoration, 2,000 offering load test and release checklist signed off before production.
