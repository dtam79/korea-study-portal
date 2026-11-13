# A01–A04 operator workflow and review checklist

## Entity registration dependency

1. University: official name in Korean and English, official university website, stable public ID.
2. Campus: university internal ID, official name, region code and address where published.
3. Program: university and campus IDs (these must belong to the same university), official name, field and level codes.
4. Intake: matching university ID, academic year, term, admission route; official dates are optional until confirmed. No inferred cut-off time.
5. Offering: program and intake IDs. The underlying repository rejects a pairing from different universities.

A university is not automatically public when an entity is created. An approved source and identity review are required.

## Field editor input forms

- Choose the **entity type and internal numeric ID** (not an institution public ID or WordPress post ID).
- Select `DET-01` to `DET-14` and a field. The editable controls derive their expected type from the live Core field catalog.
- `localized_text`: multiline plain text; `url`: HTTP(S); `year`: number; `nullable_boolean`: true/false/null; `enum`: selector; `repeat:*`: single typed JSON object or primitive plus stable `item_key`.
- Structured objects must conform to Core validators. The provided example shape is **not factual data** and must be replaced with official values before submission.
- Each source link may contain source ID, PDF page/table location, applicability, valid-from and valid-to. Multiple source links are supported.
- The `available` status requires an official value and evidence. `unpublished` or `under_review` store NULL and are not publishable; known zero and false remain distinct from unknown.
- Saving again creates another version, not an in-place overwrite. The live approved version remains available until a new one is published.

## Reviewer acceptance steps

Before approving a *source*:

- Open the original publication and verify the issuing organization is legitimate and approved.
- Confirm that the URL is public and accessible, title and page/table location match, application year or term matches, and restrictions or corrections are not missing.
- Check the source valid-from/to window and confirmation date; never infer dates or amounts.

Before approving an *identity or field*:

- Confirm each claim and its exact applicable university/program/offering/intake.
- Check unit, currency, fee period, source page, original precision and multilingual status; do not synthesize scholarship-net totals or round dates to 23:59.
- If the publication conflicts with another source, reject pending explicit official correction evidence. Do not substitute an operator assumption.
- Reject with a meaningful reason if anything is missing. Invalidate a source if it is no longer trustworthy; active public values will be suppressed by Core's existing evidence checks.

## REST read routes added by this addon

| Path | Allowed roles | Response |
| --- | --- | --- |
| `GET /kgp-operator/v1/health` | KGP editor/reviewer/manager | Core version, schema readiness |
| `GET /kgp-operator/v1/catalog` | KGP editor/reviewer/manager | DET section/field metadata |
| `GET /kgp-operator/v1/entities?type=program&q=&page=1` | reviewer/manager or scoped editor | Private paginated records only in authorized universities |
| `GET /kgp-operator/v1/queue?kind=field|identity&page=1` | reviewer | Pending reviewed snapshots, official evidence and status |
| `GET /kgp-operator/v1/sources/17` | KGP editor/reviewer/manager | Source, issuer and current verification state |
| `GET /kgp-operator/v1/versions/42` | reviewer or scoped editor | Draft/published version history with source links |

The addon itself accepts **no write requests**. Changes are sent to Core's audited `admin` endpoints. All private requests require a WordPress session with valid nonce. There are no new public endpoints.

## Milestone acceptance checklist

- [ ] DDEV local plugin loads and Study Portal menu is visible only for authorized roles.
- [ ] University/campus/program/intake/offering can be created and listed.
- [ ] Cross-university offering creation is rejected.
- [ ] Identity submit fails before valid official evidence exists.
- [ ] Source issuer and source verification require separate permissions.
- [ ] All 14 sections and repeated items are editable with typed field validation.
- [ ] Field draft is absent from `/ko/universities/` and `/wp-json/kgp/v1/catalog` until reviewer publication.
- [ ] Reviewer can approve or reject with evidence inspection; rejected draft does not replace approved data.
- [ ] Invalid source suppresses related published field and public detail output.
- [ ] A logged-out user cannot load `/kgp-operator/v1/queue` or `/kgp-operator/v1/versions/{id}`.
- [ ] Editor for University A cannot search/view University B's private records.
- [ ] Keyboard navigation, narrow administrator viewport, long source title, and error messages are usable.
