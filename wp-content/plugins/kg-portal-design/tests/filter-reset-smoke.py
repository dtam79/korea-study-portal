#!/usr/bin/env python3
"""Design v1.2: native Apply and Reset all without keyword-only reset injection."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]
js=(root/'assets/filter-controls.js').read_text()
css=(root/'assets/refresh.css').read_text()
php=(root/'kg-portal-design.php').read_text()
checks={
 'no keyword-only reset function': 'clearForKeyword' not in js and 'location.assign(' not in js,
 'native filter reset remains a plain link':'server-rendered Reset all anchor' in js,
 'native change/input dispatch unchanged':"new Event('change',{bubbles:true})" in js,
 'summary badges show applied count':'kgds-filter-status-count' in js and 'kgds-filter-active-chip' in js,
 'summary survives collapsed details':'summary.append(status)' in js,
 'selected labels highlighted':'kgds-filter-active-label' in css,
 'correct plugin version':"Version: 2.1.7" in php,
 'public 480px cap remains':'max-width:480px!important' in (root/'assets/public.css').read_text(),
}

for name,ok in checks.items():
    assert ok,name
    print('PASS:',name)
try:
    import tinycss2
    for file in [root/'assets/public.css',root/'assets/refresh.css']:
        tokens=tinycss2.parse_stylesheet(file.read_text(),skip_comments=True,skip_whitespace=True)
        errors=[x for x in tokens if x.type=='error']
        assert not errors,(file.name,errors)
        print('PASS: CSS syntax',file.name)
except ImportError:
    print('SKIP: tinycss2 CSS parse check')
