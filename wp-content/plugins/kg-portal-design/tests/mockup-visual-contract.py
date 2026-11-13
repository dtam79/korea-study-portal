#!/usr/bin/env python3
"""Design specification v1.2 visual contract, without WordPress/database changes."""
from pathlib import Path
import re,sys
root=Path(__file__).resolve().parents[1]
public=(root/'assets/public.css').read_text()
refresh=(root/'assets/refresh.css').read_text()
admin=(root/'assets/workspace.css').read_text()
assert 'max-width:480px!important' in public
assert 'max-width:480px!important' in refresh
assert 'max-width:1200px!important' not in public+refresh
assert 'grid-template-columns:minmax(0,1fr)!important' in refresh
assert 'kgpc-topic-grid {grid-template-columns:minmax(0,1fr)' in public
for size in ['28px','24px','20px','16px','14px','13px']:
 assert size in refresh, size
assert 'padding:28px 16px 44px' in refresh
assert 'background-position:right 14px center' in refresh
assert 'min-height:48px!important' in refresh
assert 'kgds-result-empty' in refresh and 'line-height:22px!important' in refresh
for cls in ['kgpc-faq','kgpa-privacy','kgp-footer','kgp-filter-panel','kgp-admission-grid','kgpa-public-form','kgpc-sources']:
 assert cls in refresh,cls
assert 'grid-template-columns:196px' in admin
assert 'max-width:1440px' in admin
assert not re.search(r'@media\s*\(min-width:1024px\).*?grid-template-columns:repeat\(2',refresh,re.S), 'unexpected desktop public grid override'
try:
 import tinycss2
 for cssname in ['public.css','refresh.css','admin.css','workspace.css']:
  s=(root/'assets'/cssname).read_text()
  errors=[r for r in tinycss2.parse_stylesheet(s,skip_comments=True,skip_whitespace=True) if r.type=='error']
  assert not errors, (cssname,errors)
except ImportError:
 print('WARN tinycss2 missing: CSS parser validation not performed')
print('PASS: all-device public 480px, one-column, 28/24/20, 48px controls, compact filter & admin isolation')
