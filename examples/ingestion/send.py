#!/usr/bin/env python3
"""Send exact saved batch bytes. Retry manually with the same file, never generate a new batch ID."""
import argparse
import hashlib
import hmac
import json
import os
import ssl
import sys
import time
import urllib.error
import urllib.request
import uuid
from pathlib import Path

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('base', help='HTTPS API base, e.g. https://cajeer.example/api/v1')
    parser.add_argument('source_id', type=uuid.UUID)
    parser.add_argument('batch', type=Path)
    parser.add_argument('--ca-file', type=Path)
    args = parser.parse_args()
    from urllib.parse import urlsplit
    url = urlsplit(args.base)
    if url.scheme != 'https' or not url.hostname or url.username or url.password or url.query or url.fragment:
        parser.error('A plain HTTPS API base is required')
    secret = os.environ.get('CAJEER_INGESTION_SECRET', '')
    if len(secret) != 64 or any(c not in '0123456789abcdef' for c in secret):
        parser.error('Set CAJEER_INGESTION_SECRET to the issued 64-character key')
    with args.batch.open('rb') as f:
        body = f.read(262145)
    if len(body) > 262144:
        parser.error('Batch exceeds 256 KiB')
    timestamp = str(int(time.time()))
    source = str(args.source_id)
    signed = b'v1.' + timestamp.encode() + b'.' + source.encode() + b'\n' + body
    signature = 'sha256=' + hmac.new(secret.encode(), signed, hashlib.sha256).hexdigest()
    request = urllib.request.Request(args.base.rstrip('/') + '/connectors/ingestion/' + source + '/batches', body, method='POST', headers={'Content-Type':'application/json','Accept':'application/json','X-Cajeer-Timestamp':timestamp,'X-Cajeer-Signature':signature})
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect(), urllib.request.HTTPSHandler(context=ssl.create_default_context(cafile=str(args.ca_file) if args.ca_file else None)))
    try:
        with opener.open(request, timeout=20) as response:
            if response.status != 202:
                raise RuntimeError('Unexpected HTTP status')
            raw=response.read(65537)
            if len(raw)>65536: raise RuntimeError('Response too large')
            print(json.dumps(json.loads(raw), ensure_ascii=False))
    except (urllib.error.URLError, RuntimeError, ValueError) as exc:
        print(f'Batch not confirmed ({type(exc).__name__}). Retry the SAME file with a fresh signature.', file=sys.stderr)
        return 1
    return 0

if __name__ == '__main__':
    sys.exit(main())
