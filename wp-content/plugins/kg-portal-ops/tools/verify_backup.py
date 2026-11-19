#!/usr/bin/env python3
"""CRC/SQL integrity of a DDEV export, NOT a database restore drill.

Streams and validates the complete gzip archive, verifies essential KGP DDL exists,
and prints only non-sensitive structural metadata. No DB access or writes.
"""
import argparse
import gzip
import hashlib
import json
import re
import sys
from pathlib import Path

CREATE = re.compile(rb'CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?', re.I)
REQUIRED = {'universities', 'offerings', 'sources', 'entity_publications'}


def verify(path):
    p = Path(path).expanduser().resolve()
    if not p.is_file() or p.stat().st_size < 50:
        raise ValueError('Missing or implausibly short SQL.gz backup.')
    if not p.name.endswith('.sql.gz'):
        raise ValueError('Expected a DDEV .sql.gz database export.')
    checksum = hashlib.sha256()
    with p.open('rb') as f:
        for block in iter(lambda: f.read(1024 * 1024), b''):
            checksum.update(block)
    names = set()
    total = 0
    tail = b''
    with gzip.open(p, 'rb') as f:
        while True:
            block = f.read(1024 * 1024)
            if not block:
                break
            total += len(block)
            combined = tail + block
            for match in CREATE.finditer(combined):
                names.add(match.group(1).decode('ascii').lower())
            tail = combined[-200:]
    suffixes = {name.split('_kgp_', 1)[-1] for name in names if '_kgp_' in name}
    missing = sorted(REQUIRED - suffixes)
    if missing:
        raise ValueError('Dump lacks required KGP CREATE TABLE definitions: ' + ', '.join(missing))
    return {
        'archive': str(p),
        'sha256': checksum.hexdigest(),
        'compressed_bytes': p.stat().st_size,
        'sql_bytes': total,
        'kgp_table_count': len(suffixes),
        'required_tables_found': sorted(REQUIRED),
        'crc_ok': True,
        'restore_tested': False,
        'warning': 'Archive integrity is not a restore drill. Restore DB and uploads into an isolated environment.',
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('backup', help='Path to DDEV .sql.gz export')
    args = parser.parse_args()
    try:
        print(json.dumps(verify(args.backup), indent=2))
    except (OSError, ValueError, EOFError) as exc:
        print('BACKUP CHECK FAILED: ' + str(exc), file=sys.stderr)
        raise SystemExit(2)


if __name__ == '__main__':
    main()
