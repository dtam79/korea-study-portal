# W2-1 / A03 -> P02 -> P03 연동 및 데이터 계약 점검

기준: 구축 기획안 v1.7, 기능명세서 v1.3, User Flow v1.3 및 화면/UI 명세서 영문 v1.2.

## 적용 변경 사항

- Core v0.4.4: `wp kgp bridge smoke`는 로컬 DDEV에서만 **2개 가상 대학, 3개 Offering**을 트랜잭션으로 작성·검증 후 롤백한다. Region/field/level/teaching_lang/tuition+unit/admission_status/certified/english_only 8개 필터 조건과 OR/AND, 비용 정렬, 대학 내 서로 다른 과정 조건 혼합 차단을 확인한다.
- Core v0.4.4: `wp kgp bridge check --limit=12`는 실제 등록된 공개 Offering의 검색 카드·상세·한국어/영어 공통 값과 출처가 일치하는지 읽기 전용으로 점검한다. 실데이터가 없다면 'SKIP'을 표시하며 완료로 간주하지 않는다. 14영역·84필드 카탈로그 계약을 검사하되, 84개 필드 전체가 실데이터로 채워졌다는 뜻은 아니다.
- Design v2.1.4: A03의 **기존 모집 회차 수정 폼**에서도 시작일이 종료일보다 늦을 경우 서버 요청 전에 안내한다. 관계 ID·잠금 버전·실제 저장은 기존 Admin/Core만 처리한다.
- Child Theme v0.5.2: 대학 상세 P03에서 목차를 클릭하거나 해시 URL로 진입할 때 해당 `<details>`를 연다. 키보드 포커스가 요약(summary)으로 이동하며 일반 해시 링크 탐색은 유지한다.

## 읽기 전용 실제 데이터 점검

```bash
ADMIN_ID="$(ddev wp user list --role=administrator --field=ID | head -n 1)"
ddev wp --user="$ADMIN_ID" kgp bridge check --limit=12
```

**주의:** 이 점검은 승인된 레코드의 구조적 일관성만 확인한다. 원본 PDF 및 대학 공식 웹사이트와의 수작업 대조, 데이터 입력 누락 여부, 모든 사용자의 브라우저 흐름 QA는 별도이다.

## 롤백형 합성 E2E

```bash
ddev wp --user="$ADMIN_ID" kgp bridge smoke
```

본 명령은 관리자 권한과 `.ddev.site` 호스트에서만 허용하며, 임의의 실제 대학·출처 데이터는 생성하지 않는다. 임시 QA 데이터는 트랜잭션에서 삭제된다. 최종 데이터베이스 변경 없음.

## 브라우저 검수

1. P02 `/ko/universities/`: OR 다중 선택, 인증·영어 100% 필터, 비용 단위·범위·정렬을 각각 확인한다.
2. P03 `/ko/universities/{id}/?offering={offering}`: 검색 카드와 동일한 회차·수업 언어·금액, DET-01~DET-14 중 승인된 섹션만 표시되는지 확인한다.
3. P03 목차: 닫힌 섹션을 선택해도 펼쳐지고 링크·뒤로 가기가 유지되는지 확인한다.
4. A03 `/wp-admin/admin.php?page=kgp-operator-entities`: 기존 모집 회차 수정에서 시작일 > 종료일인 경우 저장 전에 차단되는지 확인한다. 유효한 값은 기존 Admin PATCH 흐름으로 저장한다.

**M1/W2-1 인수 조건:** 실제 대학 2곳의 공식 출처 수동 검증과 승인, 8필터/상세/관리자 브라우저 테스트가 필요하며 이 패키지 자체만으로 완료 판정하지 않는다.
