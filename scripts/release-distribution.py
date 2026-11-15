#!/usr/bin/env python3
"""Install a sealed candidate or publish/install an independently approved stable release.

The candidate archive is immutable: approval never rewrites the accepted bytes.
The trust anchor is supplied by the operator, never taken from the distribution.
"""
import argparse
import contextlib
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tarfile
import tempfile

sys.dont_write_bytecode = True
SPEC = importlib.util.spec_from_file_location('candidate', Path(__file__).with_name('candidate-release.py'))
candidate = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(candidate)

PACKAGE = 'release-candidate.tar.gz'
CHECKSUM = 'release-candidate.sha256'
ACCEPTANCE = 'acceptance.json'
APPROVAL = 'stable-approval.json'
SIGNATURE = 'stable-approval.sig'
FILES = (PACKAGE, CHECKSUM, candidate.MARKER, candidate.SBOM)
STABLE_FILES = FILES + (ACCEPTANCE, APPROVAL, SIGNATURE)
REVIEWS = ('security', 'compatibility_matrix', 'production_capacity', 'disaster_recovery')
CAPACITY = '100k-assets/1k-targets/1m-samples/100k-events/50-clients/p95-500ms'


def read_json(path):
    if path.stat().st_size > 16 * 1024 * 1024:
        raise ValueError('Release metadata exceeds size limit')
    raw = path.read_bytes()
    data = json.loads(raw)
    if not isinstance(data, dict) or candidate.canonical(data) != raw:
        raise ValueError('Release metadata must be a canonical JSON object')
    return raw, data


def bundle(directory, expected_id):
    paths = {name: candidate.safe_path(directory, name) for name in FILES}
    raw, data = read_json(paths[candidate.MARKER])
    identity = hashlib.sha256(raw).hexdigest()
    if not candidate.HEX.fullmatch(expected_id) or identity != expected_id:
        raise ValueError('Candidate identity differs from the trusted ID')
    if data.get('format') != 1 or data.get('version') != '1.0.0' or not isinstance(data.get('files'), dict):
        raise ValueError('Expected a complete 1.0.0 candidate')
    if not set(candidate.INPUTS) <= data['files'].keys():
        raise ValueError('Candidate lacks reviewed inputs')
    package_hash = candidate.digest(paths[PACKAGE])
    if paths[CHECKSUM].read_text() != package_hash + '  ' + PACKAGE + '\n':
        raise ValueError('Archive checksum differs')
    if candidate.digest(paths[candidate.SBOM]) != data.get('sbom_sha256'):
        raise ValueError('SBOM checksum differs')
    candidate.verify_archive(paths[PACKAGE], raw, data)
    return identity, data, package_hash


def accepted(directory, expected_id, public_key):
    identity, data, package_hash = bundle(directory, expected_id)
    report_path = candidate.safe_path(directory, ACCEPTANCE)
    approval_path = candidate.safe_path(directory, APPROVAL)
    signature_path = candidate.safe_path(directory, SIGNATURE)
    report_raw, report = read_json(report_path)
    expected = {'format': 2, 'status': 'passed',
                'scope': 'strict_verification_deployment_capacity_and_http_soak',
                'version': '1.0.0', 'candidate_id': identity, 'commit': data['commit'],
                'archive_sha256': package_hash, 'strict_exit': 0, 'deployment_exit': 0,
                'capacity_profile': CAPACITY, 'promotion_ready': False}
    if any(type(report.get(key)) is not type(value) or report.get(key) != value for key, value in expected.items()):
        raise ValueError('Acceptance evidence does not match the exact release')
    # The soak allows at most 90 seconds between probe starts. Require real
    # elapsed duration and a plausible minimum number of successful probes.
    seconds, checks = report.get('soak_seconds'), report.get('soak_checks')
    started, finished = report.get('started_at'), report.get('finished_at')
    if (type(seconds) is not int or seconds < 72 * 3600 or type(checks) is not int
            or checks < 2881 or type(started) is not int or type(finished) is not int
            or finished - started < seconds):
        raise ValueError('Incomplete 72-hour acceptance evidence')
    approval_raw, approval = read_json(approval_path)
    expected_approval = {'format': 1, 'channel': 'stable', 'version': '1.0.0',
                         'candidate_id': identity, 'archive_sha256': package_hash,
                         'acceptance_sha256': hashlib.sha256(report_raw).hexdigest(),
                         'reviews': {name: 'passed' for name in REVIEWS}}
    if approval != expected_approval or type(approval.get('format')) is not int:
        raise ValueError('Independent stable approval is incomplete or mismatched')
    if not public_key.is_file() or public_key.is_symlink():
        raise ValueError('A separately trusted release public key is required')
    # Verify the exact bytes just parsed, not a later read of the mutable input.
    with tempfile.TemporaryDirectory(prefix='cajeer-signature-') as tmp:
        statement = Path(tmp) / 'approval.json'
        statement.write_bytes(approval_raw)
        result = subprocess.run(['openssl', 'pkeyutl', '-verify', '-pubin', '-inkey', str(public_key),
                                 '-rawin', '-in', str(statement), '-sigfile', str(signature_path)],
                                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=30)
    if result.returncode != 0:
        raise ValueError('Stable approval signature verification failed')
    return identity, data, package_hash


def sync_tree(root):
    # Durable file contents and directory entries before atomic publication.
    for directory, dirs, names in os.walk(root, topdown=False, followlinks=False):
        for name in names:
            with (Path(directory) / name).open('rb') as stream:
                os.fsync(stream.fileno())
        fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY)
        try: os.fsync(fd)
        finally: os.close(fd)


@contextlib.contextmanager
def new_directory(destination):
    """Single-host atomic publication into a new path, never modify an installed release."""
    parent = destination.parent
    if not parent.is_dir() or parent.is_symlink():
        raise ValueError('A trusted existing destination parent is required')
    lock_path = parent / '.cajeer-release-distribution.lock'
    fd = os.open(lock_path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'rb+') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        if destination.exists() or destination.is_symlink():
            raise ValueError('Destination already exists; refusing to overwrite')
        temp = Path(tempfile.mkdtemp(prefix='.cajeer-release-', dir=parent))
        try:
            yield temp
            sync_tree(temp)
            # Parent is controlled by the release operator; concurrent tool
            # invocations hold the same lock and cannot replace this directory.
            if destination.exists() or destination.is_symlink():
                raise ValueError('Destination appeared during publication')
            os.rename(temp, destination)
            fd = os.open(parent, os.O_RDONLY | os.O_DIRECTORY)
            try: os.fsync(fd)
            finally: os.close(fd)
        finally:
            if temp.exists(): shutil.rmtree(temp)


def stage(directory, destination, expected_id, public_key=None):
    if public_key is None:
        _, _, package_hash = bundle(directory, expected_id)
    else:
        _, _, package_hash = accepted(directory, expected_id, public_key)
    with new_directory(destination) as temp:
        # data filter refuses escaping paths and unsafe links; verify_archive
        # has already rejected every non-regular member and duplicate.
        with tarfile.open(directory / PACKAGE, 'r:gz') as tar:
            tar.extractall(temp, filter='data')
        candidate.verify_deployment(temp, expected_id)
        if candidate.digest(directory / PACKAGE) != package_hash:
            raise ValueError('Archive changed during extraction')
        # Installation state and secrets are deliberately not copied. The
        # operator runs clean install or the existing offline upgrade.
    return expected_id


def publish(directory, destination, expected_id, public_key):
    accepted(directory, expected_id, public_key)
    with new_directory(destination) as temp:
        for name in STABLE_FILES:
            shutil.copyfile(candidate.safe_path(directory, name), temp / name)
        # Recheck the published bytes, even if the input changed during copy.
        accepted(temp, expected_id, public_key)
    return expected_id


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('command', choices=('stage', 'publish', 'install'))
    parser.add_argument('--directory', type=Path, required=True)
    parser.add_argument('--destination', type=Path, required=True)
    parser.add_argument('--expected-id', required=True)
    parser.add_argument('--public-key', type=Path)
    args = parser.parse_args()
    try:
        directory = args.directory.resolve(strict=True)
        # Do not resolve the final destination component (would follow a symlink).
        destination = args.destination.parent.resolve(strict=True) / args.destination.name
        if args.command != 'stage' and args.public_key is None:
            raise ValueError('Stable publication/installation requires --public-key')
        key = args.public_key.absolute() if args.public_key else None
        if args.command == 'publish':
            publish(directory, destination, args.expected_id, key)
        else:
            stage(directory, destination, args.expected_id, key if args.command == 'install' else None)
        print('Verified release:', args.expected_id)
    except (OSError, ValueError, KeyError, TypeError, tarfile.TarError, subprocess.SubprocessError) as exc:
        print('Release distribution failed:', type(exc).__name__, str(exc) if isinstance(exc, ValueError) else '', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
