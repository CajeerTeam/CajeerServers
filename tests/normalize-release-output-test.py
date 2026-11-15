#!/usr/bin/env python3
"""Real portable symlink materialization and no-change failure tests."""
import importlib.util
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('normalizer', Path(__file__).resolve().parents[1] / 'scripts/normalize-release-output.py')
n = importlib.util.module_from_spec(spec); spec.loader.exec_module(n)

class OutputTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(); self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name); self.root = self.base / 'output'; self.root.mkdir()
        (self.root / 'server').mkdir(); self.file = self.root / 'server/index.mjs'; self.file.write_text('export {};'); self.file.chmod(0o755)
    def test_materializes_file_and_directory_aliases(self):
        (self.root / 'link.mjs').symlink_to('server/index.mjs')
        (self.root / 'alias').symlink_to('server', target_is_directory=True)
        (self.root / 'chain.mjs').symlink_to('link.mjs')
        self.assertTrue(n.normalize(self.root))
        for path in ('link.mjs', 'alias/index.mjs', 'chain.mjs'):
            p = self.root / path; self.assertFalse(p.is_symlink()); self.assertEqual(p.read_text(), 'export {};'); self.assertEqual(p.stat().st_mode & 0o777, 0o755)
        self.assertFalse((self.root / 'alias').is_symlink())
        self.assertFalse(n.normalize(self.root))
    def test_external_link_preserves_original(self):
        outside = self.base / 'outside'; outside.write_text('private')
        link = self.root / 'bad'; link.symlink_to(outside)
        with self.assertRaisesRegex(ValueError, 'Nonportable'): n.normalize(self.root)
        self.assertTrue(link.is_symlink()); self.assertEqual(outside.read_text(), 'private')
    def test_directory_cycle_preserves_original(self):
        (self.root / 'server/loop').symlink_to('..', target_is_directory=True)
        with self.assertRaisesRegex(ValueError, 'Cyclic'): n.normalize(self.root)
        self.assertTrue((self.root / 'server/loop').is_symlink())
    def test_dangling_link(self):
        (self.root / 'bad').symlink_to('missing')
        with self.assertRaisesRegex(ValueError, 'Broken'): n.normalize(self.root)
    def test_reject_symlink_root(self):
        alias = self.base / 'alias'; alias.symlink_to(self.root, target_is_directory=True)
        with self.assertRaises(ValueError): n.normalize(alias)

if __name__ == '__main__': unittest.main(verbosity=2)
