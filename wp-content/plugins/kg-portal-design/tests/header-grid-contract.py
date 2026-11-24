#!/usr/bin/env python3
"""Offline CSS contract for Gemini selector overflow observed at 360px in captured UI."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]
css=(root/'assets/refresh.css').read_text(encoding='utf-8')
header=Path(__file__).resolve().parents[3]/'themes/kg-portal-child/partials/kgp-header.php'
assert header.is_file(),header
content=header.read_text(encoding='utf-8')
assert 'class="kgp-header-actions"' in content
assert 'class="kgp-language"' in content and 'class="kgp-menu"' in content
block=css[css.index('/* Visual QA 2026-11-24 / C01:'):]
for token in ['display: contents;', 'grid-template-columns: minmax(0, 1fr) auto;',
              'grid-column: 1 / -1;', 'grid-row: 2;', 'flex-wrap: wrap;',
              'flex: 1 1 100%;', 'max-width: 100%;', 'width: 100%;',
              'select.kgpt-select', 'word-break: keep-all;']:
    assert token in block, token
assert 'body.kgp-public .kgp-site-header' in block
assert 'display: none' not in block, 'No navigation or language controls may be hidden by the fix'
try:
    import tinycss2
    assert not [x for x in tinycss2.parse_stylesheet(css,skip_comments=True,skip_whitespace=True) if x.type=='error']
except ImportError:
    print('SKIP tinycss2 CSS syntax parser; structural contract still checked')
print('PASS: single-column shared header preserves brand, native menu and Gemini language control in distinct rows.')
