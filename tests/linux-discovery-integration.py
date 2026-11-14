#!/usr/bin/env python3
"""Run the real fixed probes on a Debian/Ubuntu host and validate output through PHP."""
import importlib.util
import json
import pathlib
import subprocess
import sys
import unittest
from unittest.mock import patch

ROOT=pathlib.Path(__file__).resolve().parents[1]
AGENT=ROOT/'core/Connector/agents/linux_discovery.py'
spec=importlib.util.spec_from_file_location('probe',AGENT)
probe=importlib.util.module_from_spec(spec);spec.loader.exec_module(probe)

metric_spec=importlib.util.spec_from_file_location('metric_probe',ROOT/'core/Connector/agents/linux_metrics.py')
metric=importlib.util.module_from_spec(metric_spec);metric_spec.loader.exec_module(metric)

class Tests(unittest.TestCase):
    def test_cpu_counter_discontinuity(self):
        with patch.object(metric,'cpu',side_effect=[(10,5),(10,5),(10,5),(12,4),(12,4),(22,8)]),patch.object(metric.time,'sleep'):
            self.assertAlmostEqual(metric.cpu_ratio(),0.6)
        with patch.object(metric,'cpu',return_value=(10,5)),patch.object(metric.time,'sleep'):
            with self.assertRaises(ValueError):metric.cpu_ratio()

    def test_metric_identity_change(self):
        with patch.object(metric,'identity',side_effect=['a'*32,'b'*32]):
            with self.assertRaisesRegex(ValueError,'identity changed'):metric.collect()

    def normalize(self,raw):
        p=subprocess.run(['php',str(ROOT/'tests/linux-discovery-smoke.php'),'--normalize'],input=json.dumps(raw),text=True,capture_output=True,check=True)
        return json.loads(p.stdout)
    def test_real_probes(self):
        for scope in ('system','software','storage','network'):
            with self.subTest(scope=scope):
                raw=json.loads(subprocess.check_output([sys.executable,str(AGENT),scope],timeout=35))
                data=self.normalize(raw)
                self.assertEqual(data['scope'],scope)
                self.assertTrue(data['complete'])
                self.assertNotIn('identity',data)
                self.assertGreater(sum(data['counts'].values()),0)
                if scope=='software': self.assertIn('dpkg',[r['name'] for r in data['sections']['packages']])
                if scope=='storage': self.assertIn('/',[r['mount'] for r in data['sections']['filesystems']])
    def test_service_join(self):
        with patch.object(probe,'command',side_effect=['a.service enabled enabled\nb.service disabled enabled\n','a.service loaded active running A\nc.service loaded failed failed C\n']):
            rows=probe.services()
        result=self.normalize({'identity':'a'*32,'scope':'services','sections':{'services':rows}})
        self.assertEqual(result['counts']['services'],3)
        self.assertEqual(rows[0]['active'],'active');self.assertIsNone(rows[1]['active']);self.assertEqual(rows[2]['active'],'failed')
    def test_service_failure(self):
        with patch.object(probe,'command',side_effect=RuntimeError('failed')):
            with self.assertRaises(RuntimeError):probe.services()
    def test_package_filter(self):
        with patch.object(probe,'command',return_value='installed\tlibc6\t2.41\tamd64\nconfig-files\told\t1\tall\n'):
            self.assertEqual(probe.software(),[{'name':'libc6','version':'2.41','architecture':'amd64'}])
    def test_command_failure(self):
        with self.assertRaises(RuntimeError):probe.command(['/bin/false'])
    def test_command_bound(self):
        with self.assertRaises(ValueError):probe.command([sys.executable,'-c','print("x"*5000000)'])
    def test_no_arbitrary_scope(self):
        p=subprocess.run([sys.executable,str(AGENT),'unknown'],capture_output=True,timeout=5)
        self.assertNotEqual(p.returncode,0);self.assertEqual(p.stdout,b'')

if __name__=='__main__':unittest.main()
