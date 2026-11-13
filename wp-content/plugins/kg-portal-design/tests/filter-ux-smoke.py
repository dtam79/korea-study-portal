#!/usr/bin/env python3
"""Static and CSS parser checks; real local DDEV browser validation still required."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]
css=(root/'assets/public.css').read_text()
js=(root/'assets/filter-controls.js').read_text()
php=(root/'kg-portal-design.php').read_text()
checks={
 'select arrow uses 14px right inset':'background-position:right 14px center!important' in css,
 'select text has 44px reserved for chevron':'padding:10px 44px 10px 14px!important' in css,
 'scoped to university and admissions search':'(?:universities|admissions)' in php,
 'exactly two segmented actions':'kgds-bool-options' in js and 'kgds-bool-option' in js and 'kgds-bool-negative' not in js,
 'required choices visible':all(k in js for k in ["yes:en?'Accredited':'인증'","yes:en?'100% English degree':'100% 영어 학위'","all:en?'View all':'전체 보기'"]),
 'semantic pressed state':"setAttribute('aria-pressed'" in js and "setAttribute('role','group')" in js,
 'legacy negative values normalized to all':"select.value===values.no)nativeSet(values.all)" in js,
 'native select remains and sends input/change':'select.value=value' in js and "new Event('change',{bubbles:true})" in js,
 'native select value and name never renamed':'select.name=' not in js and 'select.remove()' not in js,
 'safe unrecognized option leaves select alone':"!Object.hasOwn(values,'yes')" in js,
 'form reset syncs segmented control':'addEventListener(\'reset\'' in js,
 'version updated':"Version: 2.1.2" in php,
 'no added database migration':'dbDelta(' not in php,
 'responsive boolean control':'@media(max-width:540px)' in css,
}
for name,passed in checks.items():
 assert passed,name
 print('PASS:',name)
try:
 import tinycss2
 rules=tinycss2.parse_stylesheet(css,skip_comments=True,skip_whitespace=True)
 errors=[r for r in rules if r.type=='error']
 assert not errors,errors
 print('PASS: full CSS parses without syntax errors')
except ImportError:
 print('SKIP: CSS parser (tinycss2 not installed)')
