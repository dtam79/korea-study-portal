# W1-2 · Two-university pilot (M1)

## Purpose

This phase tests the existing official-source workflow without modifying search, publication, schema or permissions. It adds a synthetic two-university end-to-end smoke and a separate **read-only** readiness check for two *real, existing* universities.

## Commands

```
ddev wp --user=1 kgp pilot smoke
# Only after two real universities are registered and independently verified:
ddev wp --user=1 kgp pilot readiness --university-ids=<id-a>,<id-b>
```

The smoke command is permitted only on a `*.ddev.site` hostname. It creates two synthetic universities and two separate synthetic PDF sources inside a database transaction, validates their approval, search isolation, field evidence, anonymous access and source invalidation, then intentionally rolls back. **It does not gather actual university data or publish the synthetic fixtures permanently.** The checked records may allocate auto-increment IDs even after rollback. A DB backup is required before running tests.

The readiness check never writes. It requires exactly two **public IDs** from the `universities` table (not numeric internal IDs). It flags absent or unpublished university identities, missing offerings, mismatched KO/EN canonical values, source metadata omissions, and missing source-check dates. This audit only proves the application's current read model behaves as intended. It **cannot independently establish that uploaded PDF content, applicability, dates, translations, or approvals reflect official original documents**. Manual verification and reviewer acceptance remain mandatory.

An empty DB is *not* a pass for actual-data M1. Missing university IDs or approved offerings must block readiness rather than returning success.

## M1 manual sign-off (for each real university)

1. Confirm its official name, domain and issuer against a real public original.
2. Confirm source document title, public URL, page/section locator, applicable intake, verification date and reviewing staff.
3. Approve the `university → campus → program → intake → offering` identities individually with verified evidence.
4. Publish at least one field with a verified official source and check the source panel.
5. Verify KO/EN search and detail, correct original fee units and intake precision.
6. Verify rejection, unpublishing and source invalidation do not disclose former data.
7. Record the reviewer, original document URL, review date and any exceptions. Do not mark M1 done solely on synthetic smoke.

## Core invariants

- A source URL or issuer listing alone never makes a field public.
- Publication and revision require an approved, in-date source and reviewer record.
- Unverified information stays in draft; an empty section is not represented as 0.
- A field's evidence is linked to the exact record and original locator.
- Search filters for one Offering cannot combine a different university's program, campus or intake.
- The CLI never fabricates *real* official university information.
