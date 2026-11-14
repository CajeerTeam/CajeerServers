#!/usr/bin/env python3
import importlib.util
from pathlib import Path
import unittest

root = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('audit_python_lock', root / 'scripts/audit-python-lock.py')
audit = importlib.util.module_from_spec(spec)
spec.loader.exec_module(audit)


class PythonLockAuditTest(unittest.TestCase):
    def test_real_lock(self):
        pins = audit.parse_lock(audit.LOCK.read_text())
        self.assertEqual(len(pins), 7)
        self.assertEqual(pins[0][:2], ('paramiko', '5.0.1'))

    def test_rejects_unpinned_or_empty_or_duplicate(self):
        hash_ = 'a' * 64
        for text in ('', 'paramiko>=5', 'paramiko==5.0.1', f'A==1.0 --hash=sha256:{hash_}\na==1.0 --hash=sha256:{hash_}'):
            with self.subTest(text=text), self.assertRaises(ValueError):
                audit.parse_lock(text)

    def setUp(self):
        self.pin = ('paramiko', '5.0.1', 'a' * 64)
        self.release = {'info': {'name': 'Paramiko', 'version': '5.0.1'},
                        'urls': [{'packagetype': 'bdist_wheel', 'yanked': False, 'digests': {'sha256': 'a' * 64}}],
                        'vulnerabilities': []}

    def test_accepted_release(self):
        audit.validate_release(self.pin, self.release)

    def test_rejects_yanked(self):
        self.release['urls'][0]['yanked'] = True
        with self.assertRaisesRegex(ValueError, 'non-yanked'):
            audit.validate_release(self.pin, self.release)

    def test_rejects_wrong_hash(self):
        self.release['urls'][0]['digests']['sha256'] = 'b' * 64
        with self.assertRaisesRegex(ValueError, 'hash'):
            audit.validate_release(self.pin, self.release)

    def test_rejects_wrong_release(self):
        self.release['info']['version'] = '5.0.0'
        with self.assertRaisesRegex(ValueError, 'identity'):
            audit.validate_release(self.pin, self.release)

    def test_rejects_missing_vulnerability_metadata(self):
        del self.release['vulnerabilities']
        with self.assertRaisesRegex(ValueError, 'missing vulnerability'):
            audit.validate_release(self.pin, self.release)

    def test_rejects_active_vulnerability(self):
        self.release['vulnerabilities'] = [{'id': 'TEST-ADVISORY', 'withdrawn': None}]
        with self.assertRaisesRegex(ValueError, 'TEST-ADVISORY'):
            audit.validate_release(self.pin, self.release)

    def test_allows_withdrawn_vulnerability(self):
        self.release['vulnerabilities'] = [{'id': 'TEST-ADVISORY', 'withdrawn': '2026-01-01T00:00:00Z'}]
        audit.validate_release(self.pin, self.release)


if __name__ == '__main__':
    unittest.main()
