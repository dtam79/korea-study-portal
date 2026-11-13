# v2.1.1 Boolean search controls

## Korean display

| Filter heading | Choice A (positive) | Choice B (all) |
|---|---|---|
| 인증대학 여부 | 인증 | 전체 보기 |
| 학위 과정 수업 언어 | 100% 영어 학위 | 전체 보기 |

- **전체 보기** means no filter (both certified and not certified; both fully English and other).
- The earlier **미인증만** and **해당 안 함만** buttons have been removed. No negative-only action is exposed.
- The existing native `<select>` is unchanged (name, option values, input/change events, form submission and reset). `assets/filter-controls.js` is a UI-only adapter.
- The existing backend search, WordPress REST, and operator/admin logic are byte-identical to v2.0.0.
- Legacy URL state `no/false/0` is normalized to native `all` on initialization and pageshow because it has no visual equivalent.
- Keyboard: Tab to each of the two buttons, Enter/Space to select; active option has `aria-pressed=true` and visible active styling.

## Local validation

```bash
node wp-content/plugins/kg-portal-design/tests/boolean-dom-smoke.cjs
python3 wp-content/plugins/kg-portal-design/tests/filter-ux-smoke.py
python3 wp-content/plugins/kg-portal-design/tests/logic-integrity.py
```

Manual WordPress browser check: `/ko/universities/` and `/ko/admissions/`, their `/en/` counterparts, both choice values, Apply/Reset, reload and Back.
