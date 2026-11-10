# BE-02 Domain Contracts

## Record hierarchy

`university → campus → program → offering ← intake ← university`

The same university ID MUST link the campus, program and intake. Offering IDs are immutable public IDs separate from their database primary keys. Program/Intake links cannot be changed by a generic metadata update.

## Verified field lifecycle

`create draft → edit/new version → submit pending → reviewer [approve or reject]`

- Each `field_entry` is keyed by entity + section + field + optional repeat `item_key`.
- Every saved edit creates a new immutable `field_versions` row. `draft_version_id` points to the editable candidate; `published_version_id` stays unchanged until review passes.
- `lock_version` increments on save, submit, approve, reject, or unpublish. Client must reload on version conflict.
- Every publishable version needs a linked official `source_id`, exact original location, verified and not expired evidence, and an approved issuer.
- Evidence invalidation clears the `published_version_id` of every currently affected entry in the same transaction.
- `KGP_Public_Reader` enforces approved-version and active evidence again on every read (defense in depth).
- For stale/unapproved EN translations, the current approved original is returned with `translation_pending=true` instead of stale translated text.

## Permissions

| WordPress role | Core capability | Allowed |
|---|---|---|
| `kgp_information_editor` | `kgp_edit_entries` | Assigned university draft & source entry, never publication |
| `kgp_reviewer` | `kgp_review_entries`, `kgp_verify_sources` | Evidence verification, approve/reject/unpublish |
| `kgp_inquiry_manager` | `kgp_manage_inquiries` | Future inquiry handler only |
| `kgp_portal_manager` | `kgp_manage_issuers`, `kgp_manage_assignments` | Issuer registration and editor assignments |
| `administrator` | all KGP capabilities | Administration, while proof requirements are still enforced |

There is no public endpoint in this release; public getters are PHP services for future controlled controllers.

## Known boundaries of this release

- Source verification is **an authorized human judgment recorded by code**, not automated PDF interpretation, fact checking or URL freshness validation. Real documents must be reviewed before `verify_source` is called.
- The `offering_search` table is not populated. Publishing deliberately clears its `is_public` flag for a touched offering, preventing stale public projections. A future indexing service must rebuild it from approved data.
- The 19 original tables are not all serviced by this patch. CSV, inquiry, scheduled rechecks, full translation editing, audit viewing and restoration will be implemented in their respective tasks.
- As required by the specifications, nobody should build direct REST endpoints over private source entities or use unapproved raw values in Kadence.

## v0.4.0 addition — official entity identity snapshots

Because base metadata is editable, the public catalog now requires the versioned `kgp_entity_publications` review record and verifies the linked source each time it reads. It does not trust the private entity base table or stale `offering_search` flags. This reduces the risk of accidentally exposing unapproved edits. For complete API contracts and permission descriptions see `api-v1.md`.
