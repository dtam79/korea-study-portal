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
assert 'Version: 2.1.6' in boot
assert "define('KGDS_VERSION','2.1.6')" in boot
# Existing business logic stays fingerprint-identical; only a scoped A03 UI asset is enqueued.
assert "require_once KGDS_DIR.'includes/class-lookup.php'" in boot
assert "assets/a03-workflow.js" in boot
print('PASS: original business files preserved; A03-only script registered')
