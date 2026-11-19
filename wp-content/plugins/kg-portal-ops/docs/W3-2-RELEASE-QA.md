# W3-2 release QA and operator handoff

The project remains **NOT release certified** until the automated report AND six manual gates are signed off. This workflow does not deploy, seed fake university records, change publication status, edit a live database, or perform an unsafe in-place restore.

## 1. Automated read-only release audit

In the installed DDEV project, use a real administrator ID:

```bash
ADMIN_ID="$(ddev wp user list --role=administrator --field=ID | head -n 1)"
ddev wp --user="$ADMIN_ID" kgpo release audit --mode=staging
```

Check `checks[*].status`, `failed_checks`, `manual_pending`. **The command intentionally exits 0 in report mode even if automated blockers exist**: the development patch can be committed without falsely claiming content is complete. To enforce all automated gates for a review or CI pipeline:

```bash
ddev wp --user="$ADMIN_ID" kgpo release audit --mode=staging --strict
```

On the real production host, use `--mode=production --strict` only after staging QA. Running production mode on `*.ddev.site` must fail. `release_certified` remains false until human verification; do not use the CLI exit status as approval to go live.

Automated checks: plugin/schema readiness, audited DET values and relations, runtime/HTTPS/indexing/noindex, production diagnostics, source-monitor schedule and toggle, review backlog, strict no-cache policy, publication of at least 20 universities and offerings in KO/EN, 2-university read-model parity, six current guides, five FAQ categories and three policies in each language, anonymous private REST 401/403 and public KO/EN catalog 200.

The audit inspects current WordPress data, not the truth of the original source documents. The 20-university count is a publication count, **not independent verification** that all 20 are real/accurate. A full Content/Source review still requires humans.

## 2. Security and public flows

- Confirm user/role access to A01-A09; test editor vs reviewer and unassigned university; make sure private queries do not reveal cross-university totals.
- Confirm P01-P10 in KO and EN with 320/360/390/430/768/1024/1440 px and 200% zoom, keyboard/focus, screen reader labels and all empty/error/loading states.
- Try the exact requirements T-01 through T-19: search facet OR/AND within the same Offering; card/detail parity; date-only versus exact deadline; original source locator; stale English translation; source invalidation; CSV mixed/error/stale rows; history restore to draft; inquiry permissions and consent; anti-spam and rate limiting.
- Confirm actual live SMTP receipt and privacy retention/deletion policy with operator, **without real applicant personal data in the QA screenshots**.
- Confirm WordPress/Kadence/plugin patch update on a separate staging copy and rollback if any critical path regresses.
- Validate SEO non-production noindex and production canonical/hreflang after deployment, including external CDN/cache bypass.

## 3. Database and uploads backups

The install runner exports a DB dump and (when present) a `wp-content/uploads` tar archive **outside** the WordPress web root in `../kgp-backups/`. It verifies database gzip CRC and essential KGP CREATE TABLE definitions, and verifies tar readability. This only tests **archive integrity**, not restoration.

For a real restore drill:

1. Provision a **new** isolated, access-restricted DDEV/Staging project with a distinct project name, separate database and credentials.
2. Copy the exact production/staging code, database backup and uploads archive into that environment, not the live installation.
3. Restore the dump and uploads in the isolated project only. Confirm KGP schema, 20 sample identities and offering joins, current approved values, private inquiries, attachment links, and KO/EN public routes after replacing the URL with the isolated hostname.
4. Record timestamp, backup SHA-256, actual restore duration (RTO), actual recovery point (RPO), operator, evidence location and result. Test a point-in-time recovery and rollback from a nontrivial post-backup edit.
5. Keep private inquiry data out of publicly accessible staging copies; apply access control and retention/deletion obligations.

**Never** run `ddev import-db` in `~/Projects/korea-study-portal` as a "test" of restoration, because it overwrites the working DB. Do not call the archive integrity check a restore drill.

## 4. Bounded local load probe

```bash
python3 wp-content/plugins/kg-portal-ops/tools/load_probe.py \
  --url 'https://korea-study-portal.ddev.site/wp-json/kgp/v1/catalog?locale=ko' \
  --concurrency 20 --requests 60 \
  --output ../kgp-backups/w32-local-load.json
```

Only local DDEV/localhost URLs, public GET route, 1-20 workers and 1-200 total requests are allowed. The script measures end-to-end local request time, response code and approved Offering count, and reports p50/p95. Before relying on the proposed 2-second p95 target, populate **2,000 properly scoped, approved test Offerings on isolated staging**, agree the network/host boundaries and repeat KO/EN search and detail under expected hosting. Never seed synthetic approvals into production. A passing small-local probe does not certify production performance, caching or 2,000-row readiness.

## 5. Required human signoff / release decision

| Gate | Evidence to capture | Responsible signoff |
| --- | --- | --- |
| Source and publication | 20 genuine universities; official URL, locator, date and applicability; 2-university pilot; no invented values | Source editor + reviewer |
| UAT / accessibility | P01-P10 and A01-A09; KO/EN; responsive/keyboard and original 34 mockups | Product owner + QA |
| Isolated restore | Database + uploads, attachment URLs, RTO/RPO, actual restored views | DevOps + operator |
| Performance and cache | 2,000 offerings, 20 concurrent, p95 and < 60 sec invalidation on staging | BE + DevOps |
| Operations | Cron without visitors, mail, daily offsite backup, plugin updates, monitoring and incident contacts | DevOps + operator |
| Final release | Confirm privacy/legal policy, resolved critical/high tickets, date/version and explicit signoff | Project owner |

Every gate requires its own saved evidence. If any gate is pending, record **NO-GO** instead of silently accepting a partial automated pass. Use an explicit release window, backup, documented rollback plan and post-deployment smoke results. Git commit is not deployment; Git push is a separate operator action.
