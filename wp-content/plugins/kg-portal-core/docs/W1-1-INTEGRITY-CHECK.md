# W1-1: Catalog data integrity gate

Schedule reference: `G0` (baseline), `G1` (Core 14-section / 84-field data contract), `M0` (baseline checkpoint).

## What changed

1. Official KRW money values now require **either** an exact amount **or** a complete `min_amount`/`max_amount` pair. Mixed or partial forms are rejected at new draft input. Official `0` values and existing original fee units remain valid.
2. `wp kgp integrity check` scans the installed database **without writing**: parent university/campus/program/intake/offering links; orphan field owners; approved-field pointers and types; 84-field value validation; field and identity official source validity.
3. The scanner fails closed when its `--max-fields` limit is exceeded. An empty dataset reports a warning rather than claiming live-data acceptance.

## Commands

```bash
ddev wp kgp domain check
ADMIN_ID="$(ddev wp user list --role=administrator --field=ID | head -n 1)"
ddev wp --user="$ADMIN_ID" kgp integrity check
# large environments only, when 5000 published fields are exceeded:
ddev wp --user="$ADMIN_ID" kgp integrity check --max-fields=20000
```

The CLI returns a nonzero exit code on audit issues. The read-only check does not repair records or approve sources; remediation remains subject to normal reviewer approval. It does not certify source contents, external URLs, end-user browser UX, or production readiness.

## Review gate (M0)

- `14 sections / 84 fields` contract complete.
- Cross-university parent links invalid; no broken reviewed pointers.
- No current invalid official sources on approved values or identities.
- A `WARN` for no real approved data **does not** satisfy full E2E testing. Populate and approve the planned two-university fixture during W1-2 before treating M1 as complete.
- If an issue is reported, correct through the operator workflow; do not modify DB tables directly to force a green result.

## Safe release

The release script checks clean Git, takes a DDEV DB backup, validates expected file hashes, applies changes, runs isolated PHP and local WordPress checks, and commits only if all gates pass. It never pushes to remote. If the local Core files differ, patching stops instead of overwriting unknown changes.
