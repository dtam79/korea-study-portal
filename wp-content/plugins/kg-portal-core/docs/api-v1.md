# KGP REST API v1 — Implementation contract (v0.4.0)

## Base

`/wp-json/kgp/v1/`

Public GET routes return only immutable reviewer-approved *identity snapshots* backed by currently valid verified official sources. Dynamic field values use `KGP_Public_Reader`, which independently validates every field's approval and source.

The private repository rows are never directly exposed via any public route. Admin routes require a WordPress login and server-side capability/assignment checks. For a browser logged in via WordPress cookie, include the `X-WP-Nonce` header generated with `wp_create_nonce('wp_rest')`. Never embed admin credentials or a WordPress application password in public JavaScript.

## Public routes

| Method | Route | Description |
| --- | --- | --- |
| GET | `catalog` | Offering-based search, filters, sorting, 12/page |
| GET | `admissions` | Approved offering admission calendar (unknown deadline listed without invented time) |
| GET | `universities/{public_id}?locale=en` | Reviewed university identity and publicly accessible offerings |
| GET | `offerings/{public_id}?locale=en` | Reviewed offering summary, complete 14-section structure if available, evidence links |

Supported catalog query: `locale=ko|en`, `q`, `region`, `field`, `level`, `teaching_lang`, `admission_status`, `english_only`, `certified`, `cost_unit`, `tuition_min`, `tuition_max`, `sort=name|tuition|deadline`, `page`.

Codes within one filter are comma-separated and OR-combined; filters are AND-combined. All checks apply to **one offering**, not a university-level combination of unrelated programs. Missing certified or English-only evidence does **not** mean `false`.

Tuition ranges match when the **whole published price range** lies inside the requested bounds; an overlapping but potentially over-budget range is not treated as a guaranteed match. The tuition range requires an original `cost_unit` (semester/month/year/one_time/program_total/term/other). Tuition sort additionally requires one degree level. No currency conversion, estimated aggregation, automatic scholarship subtraction or invented application cut-off time.

The detail response includes `sections` only for sections with published values. Each returned value has its own `source_documents`, verified date, ID, applicability and `translation_pending` if English text is awaiting new approval. The program, campus and intake have their own reviewed identity evidence.

## Admin routes

| Method | Route | Role / purpose |
| --- | --- | --- |
| GET | `admin/summary` | Reviewer/portal manager — pending item counts |
| GET, POST | `admin/entities/{type}` | Authenticated assigned editor/reviewer listing, create through repository |
| GET, PATCH | `admin/entities/{type}/{id}` | Private row, optimistic `expected_revision` update |
| GET | `admin/identities/{type}/{id}` | Private draft/published snapshots and lock version |
| POST | `admin/identities/{type}/{id}/submit` | Submit current identity metadata for source review |
| POST | `admin/identities/{type}/{id}/approve` | Reviewer approves immutable snapshot |
| POST | `admin/identities/{type}/{id}/reject` | Reviewer rejects snapshot, reason required |
| POST | `admin/identities/{type}/{id}/unpublish` | Reviewer removes public identity, reason required |
| GET, POST | `admin/issuers` | List and register source issuers |
| POST | `admin/issuers/{id}/approve` | Source issuer approval |
| GET, POST | `admin/sources` | List and register official documents |
| POST | `admin/sources/{id}/verify` | Reviewer verifies source |
| POST | `admin/sources/{id}/invalidate` | Reviewer invalidates source (immediate public suppression) |
| POST | `admin/assignments` | Assign editor to university |
| GET, POST | `admin/fields` | Private field listing, create new versioned field draft |
| POST | `admin/fields/{id}/submit` | Submit version for review |
| POST | `admin/fields/{id}/publish` | Reviewer approves field version with verified evidence |
| POST | `admin/fields/{id}/reject` | Reviewer rejects, reason required |
| POST | `admin/fields/{id}/unpublish` | Reviewer unpublishes field, reason required |

Entity types: `university`, `campus`, `program`, `intake`, `offering`. IDs on admin routes are **internal positive integers**; public routes use **public_id**.

### Example request bodies

- `POST /admin/identities/university/17/submit`: `{"source_id":42,"expected_lock":0,"aliases":["Accepted official alias"],"source_locator":"p. 2"}`
- `POST /admin/identities/university/17/approve`: `{"expected_lock":1}`
- `POST /admin/fields`: `{"entity_type":"offering","entity_id":21,"section":"DET-03","field":"tuition_items","item_key":"main","value":{"amount":"4200000","currency":"KRW","unit":"semester"},"source_links":[{"source_id":42,"original_locator":"p. 7"}],"expected_lock":0}`
- `POST /admin/fields/67/submit`: `{"expected_lock":1}`
- `POST /admin/fields/67/publish`: `{}`

These are **illustrative** IDs and amounts, not actual university information. All writes must be performed by an authorized account. `expected_lock` is the returned current identity or field lock version, not the WordPress post ID.

## Newly added identity-review storage

Existing v0.3.0 schema contains field-value approval but mutable base university/campus/program/intake rows. Publishing them directly would leak unverified information. `kgp_entity_publications` records a frozen reviewed identity for each type, and versions are source-gated. No existing records are deleted or auto-published. Editors can update private metadata; the currently published snapshot remains unchanged until separately reviewed.

To make a new offering publicly visible, **all five linked identities** (university, campus, program, intake, offering) must be approved. This is an implementation safeguard, not a relaxation of the original publication requirements.

## Known follow-on implementation work (not removed from final scope)

- FE pages and admin console (P01–P10, A01–A09), actual FE integration
- Formalized field-level translation draft/approval APIs
- Advanced 20-university import/CSV workflow, admin lists and recheck work queues
- Indexed offering search, measurable performance/scale and cache invalidation
- Inquiry storage/admin response workflow, scheduled jobs, backup/SEO, E2E coverage
- Validated source metadata coverage for every search classification, accessibility, and production data review

The present phase builds usable **admin writing endpoints and live public read endpoints**, but does not claim the entire project is finished.
