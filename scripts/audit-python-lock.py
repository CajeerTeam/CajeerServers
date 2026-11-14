#!/usr/bin/env python3
"""Check locked releases, wheel hashes and published PyPI vulnerability records.

No installation or package execution. Network or invalid metadata is a hard error.
"""
import json
import re
import sys
import urllib.request
from pathlib import Path

LOCK = Path(__file__).resolve().parents[1] / 'core/Connector/agents/requirements-linux-py312.lock'
PIN = re.compile(r'([A-Za-z0-9][A-Za-z0-9._-]*)==([0-9]+(?:\.[0-9]+)+) --hash=sha256:([a-f0-9]{64})')


def normalized(name):
    return re.sub(r'[-_.]+', '-', name).lower()


def parse_lock(text):
    pins = []
    seen = set()
    for line in text.splitlines():
        line = line.strip()
        if not line or line.startswith('#'):
            continue
        match = PIN.fullmatch(line)
        if match is None:
            raise ValueError('Unpinned or malformed Python lock entry')
        name, version, digest = match.groups()
        if normalized(name) in seen:
            raise ValueError('Duplicate Python lock entry: ' + name)
        seen.add(normalized(name))
        pins.append((name, version, digest))
    if not pins:
        raise ValueError('Empty Python lock')
    return pins


def validate_release(pin, data):
    name, version, digest = pin
    info = data.get('info', {})
    if normalized(info.get('name', '')) != normalized(name) or info.get('version') != version:
        raise ValueError(f'{name}: PyPI release identity mismatch')
    wheels = data.get('urls', [])
    if not any(w.get('packagetype') == 'bdist_wheel' and not w.get('yanked', False)
               and w.get('digests', {}).get('sha256') == digest for w in wheels):
        raise ValueError(f'{name}: locked non-yanked wheel hash not published by PyPI')
    vulnerabilities = data.get('vulnerabilities')
    if not isinstance(vulnerabilities, list):
        raise ValueError(f'{name}: missing vulnerability metadata')
    active = []
    for item in vulnerabilities:
        if not isinstance(item, dict) or not isinstance(item.get('id'), str) or not item['id']:
            raise ValueError(f'{name}: invalid vulnerability metadata')
        if not item.get('withdrawn'):
            active.append(item['id'])
    if active:
        raise ValueError(f'{name}=={version}: published vulnerabilities: {", ".join(active)}')


def main():
    try:
        for pin in parse_lock(LOCK.read_text(encoding='utf-8')):
            name, version, _ = pin
            url = f'https://pypi.org/pypi/{normalized(name)}/{version}/json'
            request = urllib.request.Request(url, headers={'Accept': 'application/json', 'User-Agent': 'CajeerServers-release-audit/0.9.1'})
            with urllib.request.urlopen(request, timeout=20) as response:
                if response.geturl() != url:
                    raise ValueError(f'{name}: unexpected PyPI redirect')
                body = response.read(8_000_001)
                if len(body) > 8_000_000:
                    raise ValueError(f'{name}: PyPI metadata too large')
                validate_release(pin, json.loads(body))
            print(f'{name}=={version}: published hash and vulnerability metadata verified', flush=True)
    except (OSError, ValueError, TypeError, AttributeError) as error:
        print(f'Python lock audit failed: {error}', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
