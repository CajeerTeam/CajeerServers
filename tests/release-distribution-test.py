#!/usr/bin/env python3
"""Real archive, filesystem and OpenSSL signature regressions; synthetic evidence only."""
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]

def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module

r = load('distribution', ROOT / 'scripts/release-distribution.py')
fixtures = load('fixtures', ROOT / 'tests/candidate-release-test.py')

class DistributionTests(unittest.TestCase):
    VERSION = "1.0.0"
    def setUp(self):
        self.fixture = fixtures.CandidateTests('test_real_seal_and_deploy')
        self.fixture.setUp()
        self.addCleanup(self.fixture.doCleanups)
        self.root = self.fixture.root
        self.base = self.root.parent
        self.directory = self.fixture.dest
        self.fixture.write('VERSION', self.VERSION+'\n')
        self.fixture.git('add', 'VERSION')
        self.fixture.git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-qm', self.VERSION+' fixture')
        commit = self.fixture.git('rev-parse', 'HEAD').decode().strip()
        names = [*r.candidate.INPUTS, 'storage/tmp/release-build.tar.gz', *r.candidate.output_names(self.root)]
        self.fixture.write('storage/tmp/release-build.sha256', f'version={self.VERSION}\nsource_commit={commit}\n' + ''.join(f'{r.candidate.digest(self.root/n)}  {n}\n' for n in names))
        self.identity = r.candidate.seal(self.root, self.directory)
        self.package_hash = r.candidate.digest(self.directory / r.PACKAGE)
        self.private = self.base / 'test-only-private.pem'
        self.public = self.base / 'test-only-public.pem'
        self.command('openssl', 'genpkey', '-algorithm', 'ED25519', '-out', str(self.private))
        self.command('openssl', 'pkey', '-in', str(self.private), '-pubout', '-out', str(self.public))
        self.report = {'format': 2, 'status': 'passed', 'scope': 'strict_verification_deployment_capacity_and_http_soak',
                       'promotion_ready': False, 'version': self.VERSION, 'candidate_id': self.identity, 'commit': commit,
                       'archive_sha256': self.package_hash, 'strict_exit': 0, 'deployment_exit': 0, 'capacity_profile': r.CAPACITY,
                       'soak_seconds': 259200, 'soak_checks': 4321, 'started_at': 1000000, 'finished_at': 1259300}
        if self.VERSION == '1.0.1':
            self.report['format'] = 3
            self.report['strict_verification'] = {'format':1, 'version':self.VERSION, 'candidate_id':self.identity,
                'steps':[{'name':name,'exit':0,'elapsed_ms':10} for name in sorted(r.candidate.STRICT_REQUIRED)]}
        self.sign()

    def command(self, *args):
        subprocess.run(args, check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

    def sign(self, review=None):
        raw = r.candidate.canonical(self.report)
        (self.directory / r.ACCEPTANCE).write_bytes(raw)
        approval = {'format': 1, 'channel': 'stable', 'version': self.VERSION, 'candidate_id': self.identity,
                    'archive_sha256': self.package_hash, 'acceptance_sha256': hashlib.sha256(raw).hexdigest(),
                    'reviews': {name: 'passed' for name in r.REVIEWS}}
        if review is not None: approval['reviews']['security'] = review
        statement = self.directory / r.APPROVAL
        statement.write_bytes(r.candidate.canonical(approval))
        self.command('openssl', 'pkeyutl', '-sign', '-inkey', str(self.private), '-rawin', '-in', str(statement), '-out', str(self.directory / r.SIGNATURE))

    def test_publish_install_exact_bytes(self):
        stable = self.base / 'stable'
        r.publish(self.directory, stable, self.identity, self.public)
        self.assertEqual(r.candidate.digest(stable / r.PACKAGE), self.package_hash)
        self.assertEqual(set(x.name for x in stable.iterdir()), set(r.STABLE_FILES))
        deployed = self.base / 'installed'
        r.stage(stable, deployed, self.identity, self.public)
        self.assertEqual(r.candidate.verify_deployment(deployed, self.identity)[0], self.identity)
        self.assertFalse((deployed / '.env').exists())
        self.assertFalse((deployed / 'storage/app/installed.json').exists())

    def test_stage_without_acceptance_for_staging(self):
        (self.directory / r.ACCEPTANCE).unlink()
        deployment = self.base / 'staging'
        r.stage(self.directory, deployment, self.identity)
        self.assertTrue((deployment / 'core/runtime.php').is_file())
        with self.assertRaises(ValueError): r.publish(self.directory, self.base / 'stable', self.identity, self.public)
        self.assertFalse((self.base / 'stable').exists())

    def test_short_soak_even_with_valid_signature(self):
        self.report['soak_seconds'] = 60
        self.sign()
        with self.assertRaisesRegex(ValueError, '72-hour'):
            r.publish(self.directory, self.base / 'stable', self.identity, self.public)

    def test_missing_capacity_even_with_signature(self):
        self.report['capacity_profile'] = 'tiny-fixture'
        self.sign()
        with self.assertRaisesRegex(ValueError, 'Acceptance evidence'):
            r.accepted(self.directory, self.identity, self.public)

    def test_review_failure_even_with_signature(self):
        self.sign('failed')
        with self.assertRaisesRegex(ValueError, 'approval'):
            r.accepted(self.directory, self.identity, self.public)

    def test_bad_signature_and_wrong_key(self):
        (self.directory / r.SIGNATURE).write_bytes(b'not a signature')
        with self.assertRaisesRegex(ValueError, 'signature'):
            r.accepted(self.directory, self.identity, self.public)
        self.sign()
        other = self.base / 'other.pem'
        self.command('openssl', 'genpkey', '-algorithm', 'ED25519', '-out', str(other))
        other_pub = self.base / 'other-public.pem'
        self.command('openssl', 'pkey', '-in', str(other), '-pubout', '-out', str(other_pub))
        with self.assertRaisesRegex(ValueError, 'signature'):
            r.accepted(self.directory, self.identity, other_pub)

    def test_report_tampering_after_signing(self):
        self.report['soak_checks'] += 1
        (self.directory / r.ACCEPTANCE).write_bytes(r.candidate.canonical(self.report))
        with self.assertRaisesRegex(ValueError, 'approval'):
            r.accepted(self.directory, self.identity, self.public)

    def test_wrong_candidate_and_archive(self):
        with self.assertRaisesRegex(ValueError, 'trusted ID'):
            r.stage(self.directory, self.base / 'staging', '0'*64)
        with (self.directory / r.PACKAGE).open('ab') as stream: stream.write(b'changed')
        with self.assertRaisesRegex(ValueError, 'checksum'):
            r.accepted(self.directory, self.identity, self.public)

    def test_no_overwrite(self):
        target = self.base / 'stable'
        target.mkdir(); (target / 'sentinel').write_text('existing')
        with self.assertRaisesRegex(ValueError, 'already exists'):
            r.publish(self.directory, target, self.identity, self.public)
        self.assertEqual((target / 'sentinel').read_text(), 'existing')

    def test_deployment_extra_files_and_runtime_state(self):
        target = self.base / 'staging'
        r.stage(self.directory, target, self.identity)
        (target / 'storage/app').mkdir(parents=True)
        (target / 'storage/app/installed.json').write_text('{}')
        (target / '.env').write_text('APP_ENV=local\n')
        r.candidate.verify_deployment(target, self.identity)
        (target / 'unexpected.php').write_text('<?php')
        with self.assertRaisesRegex(ValueError, 'Unexpected deployment file'):
            r.candidate.verify_deployment(target, self.identity)
        (target / 'unexpected.php').unlink()
        (target / 'admin/node_modules').mkdir()
        (target / 'admin/node_modules/extra.js').write_text('extra')
        with self.assertRaisesRegex(ValueError, 'Unexpected deployment file'):
            r.candidate.verify_deployment(target, self.identity)

    def test_failed_publication_never_leaves_target(self):
        target = self.base / 'failed'
        with self.assertRaises(RuntimeError):
            with r.new_directory(target) as temp:
                (temp / 'partial').write_text('partial')
                raise RuntimeError('simulated disk failure')
        self.assertFalse(target.exists())
        self.assertEqual([p for p in self.base.glob('.cajeer-release-*') if p.is_dir()], [])

class PatchDistributionTests(DistributionTests):
    VERSION = '1.0.1'

    def test_old_format_cannot_publish_patch(self):
        self.report['format'] = 2
        del self.report['strict_verification']
        self.sign()
        with self.assertRaisesRegex(ValueError, 'Acceptance evidence'):
            r.accepted(self.directory, self.identity, self.public)

    def test_failed_or_missing_strict_step_rejected_even_with_signature(self):
        evidence = self.report['strict_verification']
        evidence['steps'][0]['exit'] = 77
        self.sign()
        with self.assertRaisesRegex(ValueError, 'failed strict'):
            r.accepted(self.directory, self.identity, self.public)
        evidence['steps'][0]['exit'] = 0
        next(s for s in evidence['steps'] if s['name'] == 'PostgreSQL vault')['name'] = 'not a required step'
        self.sign()
        with self.assertRaisesRegex(ValueError, 'mandatory scenarios'):
            r.accepted(self.directory, self.identity, self.public)

    def test_wrong_evidence_identity(self):
        self.report['strict_verification']['candidate_id'] = '0'*64
        self.sign()
        with self.assertRaisesRegex(ValueError, 'identity mismatch'):
            r.accepted(self.directory, self.identity, self.public)

if __name__ == '__main__': unittest.main(verbosity=2)
