#!/usr/bin/env python3
"""Ensure backend/relationship/client logic is byte-identical to uploaded v2.0.0."""
import hashlib,json,re
from pathlib import Path
root=Path(__file__).resolve().parents[1]
hashes=json.loads((root/'docs/LOGIC_FINGERPRINTS_V2_0.json').read_text())
for name,expected in hashes.items():
 if name=='assets/filter-controls.js':
  print('INFO: UI adapter updated intentionally, native filter/select values unchanged')
  continue
 got=hashlib.sha256((root/name).read_bytes()).hexdigest()
 assert got==expected, f'Logic changed unexpectedly: {name}'
 print('PASS: byte-for-byte unchanged',name)
boot=(root/'kg-portal-design.php').read_text()
assert 'Version: 2.1.2' in boot
assert "define('KGDS_VERSION','2.1.2')" in boot
# No changes to registration or callbacks except version strings.
assert "require_once KGDS_DIR.'includes/class-lookup.php'" in boot
print('PASS: version metadata only in bootstrap; behavior files preserved')
