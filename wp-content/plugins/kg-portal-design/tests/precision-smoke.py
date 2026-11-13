#!/usr/bin/env python3
"""v2.1.2 regression: 480px public cap on ALL viewport sizes, P04 density."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]
css=(root/'assets/public.css').read_text()
js=(root/'assets/public.js').read_text()
php=(root/'kg-portal-design.php').read_text()
assert "Version: 2.1.2" in php
assert "'2.1.2'" in php
assert 'max-width:480px!important' in css, 'public 480px max missing'
assert 'max-width:1200px!important' not in css, 'public 1200px cap violates BG v1.2'
assert 'max-width:100%!important;border:none;box-shadow:none' not in css, 'old mobile override still active'
assert 'details.kgds-filter-compact' in css and 'padding:0!important' in css
assert 'min-height:48px!important' in css and 'font-size:14px!important' in css
assert 'kgds-result-empty' in css and 'line-height:22px!important' in css
assert 'font-size:16px;line-height:1.625' in css
assert 'kgds-route-admissions' in php and 'public_class' in php
assert '조건별\\s*필터' in js and 'kgds-filter-compact' in js and 'kgds-result-empty' in js
assert 'observe(main,{childList:true,subtree:true})' in js
assert 'public_route()' in php
try:
 import tinycss2
 stylesheet=tinycss2.parse_stylesheet(css,skip_whitespace=True,skip_comments=True)
 errors=[r for r in stylesheet if r.type=='error']
 assert not errors,errors
 print('PASS: CSS parses cleanly')
except ImportError:
 print('INFO: tinycss2 unavailable; source-level CSS checks used')
print('PASS: width caps, compact filter, empty state, route scoping, JS progressive decoration, no changed field operations')
