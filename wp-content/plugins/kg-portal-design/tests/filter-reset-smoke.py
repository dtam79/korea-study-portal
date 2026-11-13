#!/usr/bin/env python3
"""Static behavior contract for v2.1.2 keyword-only reset and active chips."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]
js=(root/'assets/filter-controls.js').read_text()
css=(root/'assets/refresh.css').read_text()
php=(root/'kg-portal-design.php').read_text()
checks={
 'keyword-only navigation preserves typed term': "url.searchParams.set(query.name,query.value)" in js,
 'no stale filter query parameters': "const url=new URL(location.pathname,location.origin)" in js,
 'reset clears all filter kinds': "form.querySelectorAll('select,input,textarea')" in js and "el.checked=false" in js,
 'native reset prevented': "event.preventDefault();event.stopImmediatePropagation();" in js,
 'native change/input dispatch unchanged': "new Event('change',{bubbles:true})" in js,
 'summary badges show applied count': "kgds-filter-status-count" in js and "kgds-filter-active-chip" in js,
 'summary survives collapsed details': "summary.append(status)" in js,
 'selected labels highlighted': 'kgds-filter-active-label' in css,
 'selected Boolean positive highlighted': '.kgds-bool.kgds-bool-active' in css,
 'reset styled as secondary action': 'button.kgds-filter-clear' in css,
 'correct plugin version': "Version: 2.1.2" in php,
 'public 480px cap remains': 'max-width:480px!important' in (root/'assets/public.css').read_text(),
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
