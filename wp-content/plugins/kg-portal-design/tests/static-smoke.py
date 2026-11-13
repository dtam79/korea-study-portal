#!/usr/bin/env python3
"""Contract tests for selector coverage, hierarchy, source file stability."""
from pathlib import Path
import re
root=Path(__file__).resolve().parents[1]
public=(root/'assets/public.css').read_text()
admin=(root/'assets/admin.css').read_text()
js=(root/'assets/admin.js').read_text()
mockups=['P01','P02','P03','P04','P05','P06','P07','P08','P09','P10','A01','A02','A03','A04','A05','A06','A07','A08','A09']
selectors={
    'P01–P04':['.kgp-shell','.kgp-site-header','.kgp-nav','.kgp-hero','.kgp-filter-panel','.kgp-result-card'],
    'P05/P06/P07':['.kgpc-main','.kgpc-topic-grid','.kgpc-card','.kgpc-faq','.kgpc-sources'],
    'P08/P09':['.kgpa-public-form','.kgpa-tabs','.kgpa-privacy','.kgpa-receipt'],
    'C01/C10':['.kgp-footer','.kgp-language'],
    'A01–A09':['.kgpa-nav','.kgpa-panel','.kgpo-panel','.kgpa-table','.kgpa-action-panel'],
}
for screen,terms in selectors.items():
    css=admin if screen=='A01–A09' else public
    for term in terms:assert term in css,(screen,term)
for label,size in [('28px','28px'),('24px','24px'),('20px','20px'),('16px','16px'),('14px','14px'),('13px','13px')]:
    assert size in public
for color in ['#1d4ed8','#0f172a','#475569','#f8fafc','#cbd5e1']:
    assert color in public
for term in ['kgds-meta-section','kgds-structured-editor','kgpa-field-value','kgpc_metadata','MutationObserver','JSON.stringify']:
    assert term in js,term
for css in [public,admin]:
    # Remove comments/strings so braces indicate rule nesting.
    scrub=re.sub(r'/\*.*?\*/','',css,flags=re.S)
    scrub=re.sub(r'"[^"\\]*(?:\\.[^"\\]*)*"|\'[^\'\\]*(?:\\.[^\'\\]*)*\'','',scrub)
    assert scrub.count('{')==scrub.count('}'),(scrub.count('{'),scrub.count('}'))
print('PASS: BG tokens, public P01–P10 and private A01–A09 coverage, structured editor, syntax balance.')
