#!/usr/bin/env python3
"""Read-only, bounded HTTP load driver; only run against an authorized staging deployment."""
import argparse
import concurrent.futures
import http.client
import json
import math
import os
import re
import ssl
import stat
import sys
import threading
import time
from urllib.parse import urlsplit


def percentile(values, p):
    return round(sorted(values)[max(0, math.ceil(len(values) * p) - 1)], 3)


def private_cookie(path):
    if not path:
        return None
    with os.fdopen(os.open(path, os.O_RDONLY | os.O_NOFOLLOW), 'r', encoding='ascii') as f:
        info = os.fstat(f.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_uid != os.getuid() or info.st_mode & 0o077 or info.st_nlink != 1:
            raise ValueError('Cookie file must be private, owned by caller and not linked')
        cookie = f.read(8193).strip()
    if not cookie or len(cookie) > 8192 or not re.fullmatch(r'[\x20-\x7e]+', cookie):
        raise ValueError('Invalid Cookie header')
    return cookie


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--origin', required=True)
    parser.add_argument('--path', action='append', required=True, help='label=/api/v1/path?query')
    parser.add_argument('--clients', type=int, default=4)
    parser.add_argument('--requests', type=int, default=1000, help='total requests, including all clients')
    parser.add_argument('--timeout', type=float, default=10)
    parser.add_argument('--p95-ms', type=float, default=0, help='optional per-path latency threshold')
    parser.add_argument('--cookie-file', help='0600 file containing Cookie header value')
    parser.add_argument('--authorized-staging', action='store_true', required=True)
    args = parser.parse_args()
    try:
        base = urlsplit(args.origin)
        if (base.scheme not in ('http', 'https') or not base.hostname or base.username is not None
                or base.password is not None or base.path not in ('', '/') or base.query or base.fragment
                or (base.scheme == 'http' and base.hostname not in ('localhost', '127.0.0.1', '::1'))):
            raise ValueError('Use HTTPS origin (plain HTTP only on loopback)')
        if not 1 <= args.clients <= 100 or not args.clients <= args.requests <= 100000 or not 0.1 <= args.timeout <= 60 or not 0 <= args.p95_ms <= 60000:
            raise ValueError('Invalid load limits')
        cases = {}
        for entry in args.path:
            label, sep, path = entry.partition('=')
            parsed = urlsplit(path)
            if (not sep or not re.fullmatch(r'[a-zA-Z0-9_-]{1,40}', label) or label in cases
                    or not path.startswith('/api/v1/') or parsed.netloc or parsed.scheme or parsed.fragment
                    or not re.fullmatch(r'[\x21-\x7e]{1,4096}', path) or '\\' in path):
                raise ValueError('Invalid path; use unique label=/api/v1/...')
            cases[label] = path
        if len(cases) > 16 or args.requests < len(cases):
            raise ValueError('Invalid number of cases')
        cookie = private_cookie(args.cookie_file)
        port = base.port or (443 if base.scheme == 'https' else 80)
    except (ValueError, OSError) as error:
        parser.error(str(error))
    headers = {'Accept': 'application/json', 'User-Agent': 'CajeerServers-benchmark/0.9.6'}
    if cookie:
        headers['Cookie'] = cookie
    items = list(cases.items())
    barrier = threading.Barrier(args.clients)
    context = ssl.create_default_context()

    def client(index):
        connection = None
        samples = {label: [] for label in cases}
        errors = {label: 0 for label in cases}
        barrier.wait()
        for n in range(index, args.requests, args.clients):
            label, path = items[n % len(items)]
            start = time.perf_counter()
            try:
                if connection is None:
                    if base.scheme == 'https':
                        connection = http.client.HTTPSConnection(base.hostname, port, timeout=args.timeout, context=context)
                    else:
                        connection = http.client.HTTPConnection(base.hostname, port, timeout=args.timeout)
                connection.request('GET', path, headers=headers)
                with connection.getresponse() as response:
                    body = response.read(4 * 1024 * 1024 + 1)
                    if response.status != 200 or len(body) > 4 * 1024 * 1024 or 'json' not in response.getheader('Content-Type', '').lower():
                        raise ValueError('Unexpected response')
                    if not isinstance(json.loads(body), (dict, list)):
                        raise ValueError('Invalid JSON response')
            except (OSError, ValueError, http.client.HTTPException):
                errors[label] += 1
                if connection is not None:
                    connection.close()
                    connection = None
            else:
                samples[label].append((time.perf_counter() - start) * 1000)
        if connection is not None:
            connection.close()
        return samples, errors

    start = time.perf_counter()
    samples = {label: [] for label in cases}
    errors = {label: 0 for label in cases}
    with concurrent.futures.ThreadPoolExecutor(max_workers=args.clients) as pool:
        for result, failed in pool.map(client, range(args.clients)):
            for label in cases:
                samples[label].extend(result[label])
                errors[label] += failed[label]
    elapsed = time.perf_counter() - start
    report = {}
    for label, values in samples.items():
        report[label] = {'success': len(values), 'errors': errors[label], 'p50_ms': percentile(values, .5) if values else None,
                         'p95_ms': percentile(values, .95) if values else None, 'p99_ms': percentile(values, .99) if values else None,
                         'max_ms': round(max(values), 3) if values else None}
    passed = all(row['success'] > 0 and row['errors'] == 0 and (not args.p95_ms or row['p95_ms'] <= args.p95_ms) for row in report.values())
    print(json.dumps({'clients': args.clients, 'requests': args.requests, 'seconds': round(elapsed, 3),
                      'successful_requests_per_second': round(sum(map(len, samples.values())) / elapsed, 2),
                      'cases': report, 'passed': passed}, indent=2))
    return 0 if passed else 1


if __name__ == '__main__':
    sys.exit(main())
