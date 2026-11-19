#!/usr/bin/env python3
"""Bounded read-only local DDEV REST latency probe (not a 2,000-Offering certificate)."""
import argparse
import concurrent.futures
import json
import math
import sys
import time
import urllib.error
import urllib.parse
import urllib.request


def allowed_url(url):
    parsed = urllib.parse.urlparse(url)
    host = (parsed.hostname or '').lower()
    return (parsed.scheme in ('http','https') and not parsed.username and not parsed.password and
            (host.endswith('.ddev.site') or host in ('localhost','127.0.0.1')) and
            parsed.path.rstrip('/') == '/wp-json/kgp/v1/catalog' and parsed.port in (None,80,443))


def percentile(values, p):
    if not values:
        raise ValueError('No response timings.')
    ordered = sorted(values)
    return ordered[min(len(ordered)-1,max(0,math.ceil(len(ordered)*p)-1))]


def summarize(records, concurrency):
    good = [r['elapsed_ms'] for r in records if r['status'] == 200 and r['valid']]
    successful = len(good)
    sample_total = min((r['total'] for r in records if r['status']==200 and r['valid']), default=None)
    return {
        'requests': len(records), 'concurrency': concurrency, 'successful': successful,
        'failed': len(records)-successful,
        'p50_ms': round(percentile(good,0.5),2) if good else None,
        'p95_ms': round(percentile(good,0.95),2) if good else None,
        'approved_offerings_seen': sample_total,
        'local_probe_target_met': sample_total is not None and sample_total>=2000 and concurrency>=20 and
                                  len(records)==successful and percentile(good,0.95)<=2000,
        'release_performance_certified': False,
        'note': 'Local-only load measurement; NOT proof of production hosting/caches or independent approved fixtures.',
    }


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None  # Never follow local staging redirects to a different host.


def request(url, timeout):
    start=time.perf_counter()
    status=0;valid=False;total=0
    try:
        request_obj=urllib.request.Request(url,headers={'Accept':'application/json','User-Agent':'KGP-local-QA/1.0','Cache-Control':'no-cache'})
        opener=urllib.request.build_opener(NoRedirect)
        with opener.open(request_obj,timeout=timeout) as resp:
            status=resp.status
            data=json.load(resp)
            valid=isinstance(data,dict) and isinstance(data.get('items'),list) and isinstance(data.get('total'),int)
            total=data.get('total',0) if valid else 0
    except (OSError,ValueError,urllib.error.URLError):
        pass
    return {'elapsed_ms':(time.perf_counter()-start)*1000,'status':status,'valid':valid,'total':total}


def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--url', required=True, help='Local .ddev.site /wp-json/kgp/v1/catalog?locale=ko')
    p.add_argument('--concurrency',type=int,default=20)
    p.add_argument('--requests',type=int,default=60)
    p.add_argument('--timeout',type=int,default=12)
    p.add_argument('--output',help='Optional local JSON results file, ideally OUTSIDE the web root')
    args=p.parse_args()
    if not allowed_url(args.url):p.error('Only local *.ddev.site or localhost public KGP catalog URLs are allowed.')
    if not 1<=args.concurrency<=20 or not 1<=args.requests<=200 or not 2<=args.timeout<=20:
        p.error('Limits: 1..20 concurrency, 1..200 requests, 2..20 second timeout.')
    with concurrent.futures.ThreadPoolExecutor(max_workers=args.concurrency) as pool:
        results=list(pool.map(lambda _:request(args.url,args.timeout),range(args.requests)))
    report=summarize(results,args.concurrency)
    print(json.dumps(report,indent=2))
    if args.output:
        from pathlib import Path
        output=Path(args.output).expanduser()
        if output.exists():p.error('Refusing to overwrite an existing QA evidence file.')
        output.parent.mkdir(parents=True,exist_ok=True)
        output.write_text(json.dumps(report,indent=2)+'\n',encoding='utf-8')
    if report['failed']:
        print('FAIL: Some public reads failed or returned an invalid response.',file=sys.stderr)
        raise SystemExit(1)
    if not report['local_probe_target_met']:
        print('REVIEW: Load probe is not a release performance certificate; check 2,000 approved offerings and p95.',file=sys.stderr)


if __name__ == '__main__':
    main()
