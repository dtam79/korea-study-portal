#!/usr/bin/env python3
"""Only UI scroll affordance; compare to native filter request contract (read-only)."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]
js=(root/'assets/filter-controls.js').read_text(encoding='utf-8')
css=(root/'assets/refresh.css').read_text(encoding='utf-8')
theme=(root.parent.parent/'themes/kg-portal-child/inc/public-template.php').read_text(encoding='utf-8')
for token in ['refreshScrollHint(grid)','prepareScrollHints(form)',"form.querySelectorAll('.kgp-chip-grid')",'grid.scrollHeight>grid.clientHeight+3','kgds-filter-scroll-note','aria-describedby']:
    assert token in js,token
for token in ['.kgds-filter-form .kgp-chip-grid','scrollbar-gutter:stable','scroll-padding-bottom:14px','overflow-y:auto','[hidden]{display:none!important}']:
    assert token in css,token
assert 'name="<?php echo esc_attr($key); ?>[]"' in theme
assert 'type="checkbox"' in theme
assert 'kgp-filter-client-error' in theme
assert "grid.querySelectorAll('input')" not in js
print('PASS: scroll hints are visual-only; native filter codes and request contracts unchanged')
