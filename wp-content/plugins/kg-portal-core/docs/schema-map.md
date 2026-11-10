# BE-02 Data Model Mapping

## Domain identity

- `university_id`: University. Stable `public_id`; official Korean/English names, institution type and official website.
- `campus_id`: Campus; belongs to one university, including a primary record when no multi-campus distinction exists.
- `program_id`: Degree or language-course program. Belongs to university and campus; includes field, level and teaching language metadata.
- `intake_id`: Admission cycle at a university; preserves date precision and timezone.
- `offering_id`: Search/detail unit. Refers to a **specific** program + intake + track/variant. Repositories must verify university and campus consistency.

## Evidence and field data

- `issuer_id`: Approved official source organization. Approval does not automatically approve content.
- `source_id`: Official document/URL, checked by and reviewed by, validity scope/time and evidence status.
- `field_entries`: A logical field or repeat item (`entity_type`, `entity_id`, `section_code`, `field_key`, `item_key`), with **separate** pointers to draft and published revisions. ID/lock_version for concurrency.
- `field_versions`: Versioned typed JSON payload plus status and review provenance. The catalog in `FieldCatalog.php` documents all DET-01..DET-14 keys. Nested items with differing evidence/periods must get distinct `item_key` entries.
- `field_version_sources`: References sources **per version**, retaining precise page/table/URL locator and applicable scope/period.
- `translations`: Locale-specific text attached to the original version; shared amounts/dates should never be overwritten by translations.
- `offering_search`: A projection of currently publishable, approved facts; **empty by default** and never manually edited. Future publication service owns refresh and invalidation.

## Operations

- `user_assignments`: Restricts writer/reviewer scope to university; role authorization still required on every request.
- `audit_events`: Actor, time and before/after; sensitive info must be redacted/minimized from audit logs.
- `import_batches`/`import_rows`: Separate import preview and actual (draft-only) acceptance; idempotency is enforced later at the service layer.
- `inquiries`/`inquiry_events`: Private data, policy version, consent time, deletion deadline, assignment and status. No public API; collection blocked until policy is signed off.
- `recheck_tasks`: Future scheduled verification workflow per source/field entity.

## Source-to-implementation coverage (not completed feature status)

| Requirement | Persistence model | Feature implementation status |
| --- | --- | --- |
| USR-03/04 filtering | offering entity + offering_search | NOT IMPLEMENTED |
| DET-01...DET-14 | field_entries + field_versions + catalog | STORAGE DEFINED; NOT YET WRITABLE VIA APP |
| SRC-01...07 | issuers, sources, field_version_sources | STORAGE DEFINED; APPROVAL NOT IMPLEMENTED |
| ADM-03...12 | domain tables, versions, assignments | STORAGE DEFINED; ADMIN UI AND WORKFLOW NOT IMPLEMENTED |
| LNG-01...04 | translations | STORAGE DEFINED; REVIEW/READ FALLBACK NOT IMPLEMENTED |
| DAT-01...07 | import_batches/rows, audit, versions | STORAGE DEFINED; CSV AND RESTORE NOT IMPLEMENTED |
| USR-18...20, ADM-01/02 | inquiries, inquiry_events | STORAGE DEFINED; PRIVACY/CONTACT NOT IMPLEMENTED |
| OPS-04/06/07 | recheck_tasks, audit | STORAGE DEFINED; SCHEDULING/BACKUP NOT IMPLEMENTED |

## Pending decisions to record — not reasons to shrink scope

- The approved exact field spec for each of 14 DET sections and structured value shapes (especially money, student disclosure stats and official corrections).
- ACF / WPML / Kadence Pro licensing and exact integration boundaries.
- Search OR/AND defaults, comparable unit selection, pagination acceptance and performance targets.
- Production-specific privacy retention/deletion, user role assignment and review staffing.
- Test fixture coverage including university/program/intake collisions and invalid/expired evidence.

No functionality may be treated as complete merely because a table exists.
