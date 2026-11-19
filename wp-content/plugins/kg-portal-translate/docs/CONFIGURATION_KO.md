# 브라우저 언어 자동 번역 설정

1. 이전 W3-2까지 테스트와 Git 커밋을 완료한 후 이번 패키지를 설치합니다.
2. Google AI Studio에서 Gemini API 키를 생성합니다. API 키는 **브라우저/JS/플러그인 ZIP/Git에 넣지 않습니다.**
3. 사용자의 Mac 로컬 WordPress `wp-config.php`에 아래 설정을 추가하거나 웹 서버에서 `GEMINI_API_KEY` 환경변수로 주입합니다.

```php
// wp-config.php: local/prod secret. NEVER commit.
define('KGP_GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: 'REPLACE_WITH_YOUR_SERVER_SIDE_KEY');
// 선택: 모델 변경. 기본값은 gemini-3.1-flash-lite.
// define('KGP_GEMINI_MODEL', 'gemini-3.1-flash-lite');
```

**보안 권장:** 위 코드의 `REPLACE_WITH...` 자리에 키를 소스 저장소로 직접 포함시키는 대신 `GEMINI_API_KEY`를 WordPress PHP 서버 환경변수로 구성하세요. DDEV는 웹 컨테이너 환경변수를 사용해야 하며, Mac 터미널에서 `export`만 한 값이 DDEV PHP에 전달되는 것은 아닙니다.

4. 브라우저 설정에서 우선 언어를 `Tiếng Việt` 또는 `Español`로 지정하고 `/ko/`를 방문합니다. 자동으로 `/en/` 기준 공개 페이지로 이동해 자동 번역합니다. 
5. KO/EN 링크는 수동 승인 번역을 보여주고, 별도의 `Language / 언어` 선택창에서 사용자가 원하는 언어 또는 `Auto (browser)`를 선택할 수 있습니다. 선택은 해당 브라우저에만 저장됩니다.
6. 번역 요청은 **공개 HTML로 WordPress 서버가 생성·보관한 manifest**만 허용합니다. REST 요청에 텍스트를 넣어도 모델에 전달하지 않습니다. HTML UI 값·SEO 메타·URL·DB 원본은 바꾸지 않으며, 소스가 바뀌면 캐시 키도 변경됩니다.

## 정책 및 주의 사항

- 날짜·수치가 포함된 텍스트는 원문을 유지합니다. **자동 번역은 공식 승인 번역이 아닙니다.** 중요 조건·학비·마감일은 반드시 연결된 공식 출처에서 다시 확인하세요.
- `ko`·`en`에서 Gemini를 호출하지 않습니다. 관리자, REST, POST, 문의 폼·문의 접수 결과에는 Gemini를 호출하지 않습니다. 검색어(`q`)는 폼 입력값으로 유지하며 Gemini로 전달하지 않습니다. 서버 화면에 검색어가 그대로 반영되는 문구도 별도 제외합니다.
- 폼 `value`, 사용자가 입력한 텍스트, private/admin 페이지, 원문 URL, API 키는 Gemini 요청에 포함되지 않습니다. 공개 페이지의 짧은 문구/본문 및 정적 접근성 라벨만 전송됩니다.
- 지원 목록의 다른 언어는 모델 자체의 지원 여부·API 사용 한도에 따라 성공률이 다릅니다. 호출 실패 또는 사용 한도 초과 시 KO/EN 승인 문구로 복귀합니다.
- 모델 호출은 1개 공개 페이지의 최대 240개 짧은 문구, 약 24KB, 호출당 25개 문구·4.5KB로 제한하고, 서버 캐시(2주)·호출 상한(IP당 시간당 50회/사이트 하루 600회)을 사용합니다. 상한은 기본 보호 수준이며 대규모 공개 서비스에는 서버/프록시 단계의 별도 Rate limiting을 권장합니다.
- 사용량에 따른 Gemini 요금이 발생할 수 있습니다. Google Gemini API 이용약관 및 데이터 처리 조건을 검토하세요. 개인정보가 포함된 승인되지 않은 콘텐츠를 공개하지 마세요.
- Gemini 시스템에서만 작동하는 자동 번역은 기본언어의 canonical SEO 버전을 바꾸지 않습니다. 검색엔진 색인용 다국어 페이지를 따로 생성하지 않습니다.
- 페이지가 캐시된 상태에서 manifest가 만료되면 페이지를 새로고침해 복구할 수 있습니다. 공개 페이지 전체 HTML을 장기 캐시하는 경우 해당 페이지 캐시 TTL을 1주 이하로 유지하세요.

## 점검 명령

```bash
ddev wp plugin is-active kg-portal-translate
ddev wp eval 'require WP_PLUGIN_DIR . "/kg-portal-translate/tests/wp-readonly.php";'
```

실제 API 사용 여부는 **관리자가 키를 구성한 후** 비KO/EN 언어 브라우저에서 확인합니다. 이번 설치 자동 테스트는 실제 Gemini에 과금되는 요청을 하지 않습니다.
