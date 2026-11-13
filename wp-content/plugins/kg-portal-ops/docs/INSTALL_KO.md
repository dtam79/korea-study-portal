# KG Portal Operations v1.0.0 — 설치 및 검수 안내

이번 단계는 기존 WP 코드에 독립적으로 설치하는 `kg-portal-ops` 플러그인입니다. **홈·대학 검색·가이드·FAQ·문의·공통 Footer의 소스는 변경하지 않습니다.** 기존 `kg-portal-core`, `kg-portal-admin`, `kg-portal-content`는 유지합니다.

## 개발 환경 적용

프로젝트 폴더에서 실행:

```bash
cd ~/Projects/korea-study-portal
mkdir -p ../kgp-backups
ddev export-db --file=../kgp-backups/before-ops-v100.sql.gz
unzip -o ~/Downloads/kg-portal-ops-v1.0.0.zip -d wp-content/plugins/
ddev wp plugin activate kg-portal-ops
ddev wp eval 'KGPO_Schema::install();'
ddev wp rewrite flush
```

## 필수 테스트

```bash
ddev exec php wp-content/plugins/kg-portal-ops/tests/bootstrap-smoke.php
ddev exec php wp-content/plugins/kg-portal-ops/tests/offline-smoke.php
ddev exec php wp-content/plugins/kg-portal-ops/tests/static-smoke.php
ddev exec node wp-content/plugins/kg-portal-ops/tests/js-smoke.cjs

ddev wp user list --fields=ID,user_login,roles
ddev wp --user=YOUR_ADMIN_LOGIN eval-file wp-content/plugins/kg-portal-ops/tests/wp-smoke.php
```

`YOUR_ADMIN_LOGIN`을 실제 WordPress 관리자 사용자명으로 교체합니다. 실행 환경에 Node가 없으면 Node 기반 테스트는 Mac 호스트에서 `node .../tests/js-smoke.cjs`로 실행해도 됩니다.

## 운영 화면

- `/wp-admin/admin.php?page=kgpo-operations` — 재확인 기한·검토 대기·스케줄러 상태
- `/wp-admin/admin.php?page=kgpo-sources` — 공식 출처별 확인 주기·담당자·검사 기록
- `/wp-admin/admin.php?page=kgpo-review` — 변경 가능성/접속 오류 원문 대조 후 검토 기록
- `/wp-admin/admin.php?page=kgpo-seo` — 자동 점검 설정·검색엔진 정책·캐시 관리

**자동 원문 검사 초기값은 OFF**입니다. 새 출처의 유형과 주기를 운영자가 지정하고, 실제 원문 비교가 가능할 때 활성화합니다. 모집일 7일, 대학 기본정보 90일 등은 운영 기본값일 뿐, 공식 정보의 유효기간을 의미하지 않습니다.

검사 결과의 `baseline`은 비교 기준 저장, `unchanged`는 표본 기준 동일, `changed`는 변경 *가능성*, `unavailable`은 접속 실패입니다. 자동 결과는 **원문의 진위를 판정하지 않습니다.** 검토자는 Core 관리자에서 실제 원문을 열어 변경·정정 공고·적용 회차를 직접 확인한 뒤 승인하거나 무효화해야 합니다.

## 사이트맵과 공통 화면

- 로컬 `https://korea-study-portal.ddev.site/kgp-sitemap.xml`은 **404가 정상**입니다. 개발/검수 환경을 검색엔진이 수집하지 않도록 제한합니다.
- 운영 도메인에서만 승인된 대학·가이드·정책과 공개 색인 페이지를 XML 사이트맵에 포함합니다.
- 이 단계는 홈페이지를 비롯한 기존 공개 템플릿을 새로 출력하지 않으므로, **이미 통합한 Header·Footer는 그대로 유지**됩니다.
- 캐시 기본값은 데이터 최신성을 위해 `no-store`입니다. WP-Optimize 파일 캐시와 CDN이 이를 따르는지는 운영 서버에서 별도로 확인해야 합니다.

## 외부 Cron 안내

플러그인은 방문이 있을 때 실행되는 WP-Cron에 매시 최대 15개 확인 작업을 등록합니다. 방문이 없는 경우 보장되지 않으므로 실제 운영 서버의 Cron을 설정해야 합니다. 개발 환경에서는 다음을 수동으로 실행할 수 있습니다.

```bash
ddev wp kgpo status
ddev wp kgpo scan --limit=15
```

운영 서버에서는 실제 WP-CLI 실행 경로와 WordPress 경로를 지정하고 네트워크 접근·오류 로그를 점검해야 합니다. 자동 검사에 최대 수십 초가 소요될 수 있으므로 Cron 실행 시간을 적절히 분산하세요.

## 제한 및 다음 검수 항목

- DDEV WordPress 통합 테스트와 실브라우저 화면 검사는 이 패키지를 생성한 환경에서 실행할 수 없었습니다.
- PDF 전체 해시 비교, 정정 공지 의미 분석, 외부 캐시/CDN 즉시 무효화, 실제 2,000 조건 묶음 p95 성능 검증은 별도 실환경 검수·강화가 필요합니다.
- 실제 공식 정보 수집·출처 승인·20개 대학·한영 번역 완료는 자동 검사로 대체하지 않습니다.
- 종료할 때 `ddev wp plugin deactivate kg-portal-ops`를 실행하면 스케줄러가 해제되고 기존 데이터는 유지됩니다.
