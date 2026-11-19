#!/usr/bin/env python3
"""W3-2 test, no network/WordPress required."""
import gzip
import importlib.util
import tempfile
from pathlib import Path

ROOT=Path(__file__).resolve().parent


def read(name):
    spec=importlib.util.spec_from_file_location(name,ROOT/(name+'.py'))
    module=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module

backup=read('verify_backup')
load=read('load_probe')
with tempfile.TemporaryDirectory() as directory:
    root=Path(directory)
    path=root/'demo.sql.gz'
    sql=b'-- DDEV synthetic test archive\n'
    for table in ['universities','offerings','sources','entity_publications']:
        sql+=f'CREATE TABLE `wp_kgp_{table}` (id bigint);\n'.encode()
    with gzip.open(path,'wb') as out:out.write(sql)
    meta=backup.verify(path)
    assert meta['crc_ok'] and not meta['restore_tested'] and meta['kgp_table_count']==4
    print('PASS: Valid structural DDEV dump checksums and tables checked; no restore claims.')
    missing=root/'missing.sql.gz'
    with gzip.open(missing,'wb') as out:out.write(b'CREATE TABLE wp_other(id int);\n'*3)
    try:backup.verify(missing)
    except ValueError:pass
    else:raise AssertionError('Missing KGP tables must fail.')
    print('PASS: Incomplete KGP dumps are rejected.')
    bad=root/'corrupt.sql.gz'
    bad.write_bytes(path.read_bytes()[:-6]+b'xxxxxx')
    try:backup.verify(bad)
    except (OSError,EOFError):pass
    else:raise AssertionError('Corrupt gzip CRC must fail.')
    print('PASS: Corrupted gzip archive is rejected.')
assert load.allowed_url('https://korea-study-portal.ddev.site/wp-json/kgp/v1/catalog?locale=en')
for url in ['https://www.example.org/wp-json/kgp/v1/catalog','http://169.254.169.254/wp-json/kgp/v1/catalog','https://user:pass@foo.ddev.site/wp-json/kgp/v1/catalog',
            'https://foo.ddev.site/wp-admin/','file:///etc/passwd','https://foo.ddev.site:444/wp-json/kgp/v1/catalog']:
    assert not load.allowed_url(url),url
print('PASS: Load probe refuses production, private, credential-bearing and off-port URLs.')
assert load.NoRedirect().redirect_request(None,None,302,'Found',{},'https://example.org/') is None
print('PASS: Load probe does not follow redirects to external sites.')
assert load.percentile([100,200,300,400,500],0.95)==500
records=[{'elapsed_ms':i*50,'status':200,'valid':True,'total':2000} for i in range(1,21)]
assert load.summarize(records,20)['local_probe_target_met']
assert not load.summarize(records,20)['release_performance_certified']
records[-1]['status']=403
assert not load.summarize(records,20)['local_probe_target_met']
print('PASS: p95 and release-scale gate fail closed on HTTP errors.')
print('SUCCESS: W3-2 offline tool regressions passed.')
