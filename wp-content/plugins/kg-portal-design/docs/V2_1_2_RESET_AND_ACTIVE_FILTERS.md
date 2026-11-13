# v2.1.2 — Keyword-only reset + active criteria UI

## Interaction contract
1. Enter text in the original keyword input (`q`, `keyword`, `search`, `s`, `query`, or `term`).
2. Apply any region, program, accreditation, language, fee or sort filters using the existing form.
3. The "조건별 필터" disclosure shows the number of nondefault options and up to two selected labels in blue. More choices show `+N`. The count is visible while collapsed.
4. Click "필터 초기화". The browser navigates to the same public listing path with **only** the keyword in query parameters. Every advanced filter is removed. Without any keyword, the route returns to its unfiltered listing.
5. Native selects remain the source of truth for the existing search submission; the public REST/API layer and Core query parsing are unchanged.

## UI typography
- Normal label: 13px, #0F172A.
- Active filter label: 14px, weight 750, #1D4ED8.
- Summary count pill: 12px, weight 750, white/blue emphasis.
- Active value chips: 12px, line-height 18px, #EFF6FF / #1E40AF.
- Secondary reset control: min-height 44px, 13px label, neutral border, strong focus outline.

## Scope
Only public universities/admissions routes in Korean and English. No Admin/REST/data models changed. UI adapter and final refresh.css only, plus version/tests/docs. v2.0.0 logic hashes stay unchanged.

## QA caveat
Offline DOM test verifies the default `q` keyword convention, native boolean values, label highlighting and stripping arbitrary filter parameters. Live theme tests must verify query field naming, form layout and results after navigation.
