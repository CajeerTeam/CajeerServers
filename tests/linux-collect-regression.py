#!/usr/bin/env python3
"""Run the real Linux inventory collector and PHP observation validator."""
import importlib.util
import json
import pathlib
import subprocess
import sys
import unittest
from unittest.mock import patch

ROOT = pathlib.Path(__file__).resolve().parents[1]
SCRIPT = ROOT / 'core/Connector/agents/linux_collect.py'
spec = importlib.util.spec_from_file_location('linux_collect', SCRIPT)
probe = importlib.util.module_from_spec(spec)
spec.loader.exec_module(probe)

VALIDATE = r'''
require $argv[1].'/core/autoload.php';
try {
    $data=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
    $result=\Cajeer\Servers\Connector\HostObservation::result($data,'ssh','sync');
    $manifest=new \Cajeer\Servers\Connector\ConnectorManifest('ssh','ssh',['ssh_password','ssh_private_key'],\Cajeer\Servers\Connector\ConnectorManifest::OPERATIONS,['server'],'ssh');
    $result=$result->validated($manifest);
    if (!$result->complete || count($result->items)!==1) exit(2);
    echo json_encode($result->items[0]['fields']['attributes']['host_hardware'],JSON_THROW_ON_ERROR);
} catch (Throwable $e) { fwrite(STDERR, 'Observation rejected: '.$e::class."\n"); exit(1); }
'''


def validate(data):
    result = subprocess.run(['php', '-r', VALIDATE, str(ROOT)], input=json.dumps(data), text=True,
                            capture_output=True, check=True, timeout=15)
    return json.loads(result.stdout)


class Tests(unittest.TestCase):
    def snapshot(self, disks, release='ID=ubuntu\nVERSION_ID="24.04"\nPRETTY_NAME="Ubuntu 24.04"'):
        files = {'/etc/machine-id': 'ec2cde4264f461b4d3e83fce63d0b813',
                 '/etc/os-release': release, '/proc/meminfo': 'MemTotal: 4096 kB'}
        def query(args):
            if args[0] == 'lsblk':
                self.assertIn('-l', args)
                return {'blockdevices': disks}
            self.assertEqual(args, ['ip', '-j', 'address', 'show'])
            return [{'ifname': 'lo', 'addr_info': [{'family': 'inet', 'local': '127.0.0.1'}]},
                    {'ifname': 'enp39s0', 'addr_info': [{'family': 'inet', 'local': '192.0.2.1'},
                                                       {'family': 'inet6', 'local': 'fe80::1'}]}]
        with patch.object(probe, 'read', side_effect=files.__getitem__), patch.object(probe, 'query', side_effect=query), \
                patch.object(probe.socket, 'gethostname', return_value='aws-fixture'), patch.object(probe.os, 'cpu_count', return_value=4):
            return probe.collect()

    def test_zero_capacity_device_does_not_break_inventory(self):
        rows = [{'name': 'zram0', 'size': 0, 'type': 'disk'},
                {'name': 'nvme0n1', 'size': 161061273600, 'type': 'disk'},
                {'name': 'nvme0n1p1', 'size': 100, 'type': 'part'}]
        data = self.snapshot(rows)
        self.assertEqual(data['disks'], [{'name': 'nvme0n1', 'size_bytes': 161061273600}])
        self.assertEqual(validate(data)['cpu_count'], 4)
        # The old collector would have included the zero-sized disk; prove it
        # is actually rejected by the unchanged production PHP validator.
        data['disks'].append({'name': 'zram0', 'size_bytes': 0})
        with self.assertRaises(subprocess.CalledProcessError):
            validate(data)

    def test_repeated_identical_disk_is_one_observation(self):
        rows = [{'name': 'nvme0n1', 'size': 161061273600, 'type': 'disk'},
                {'name': 'nvme0n1', 'size': '161061273600', 'type': 'disk'}]
        self.assertEqual(len(validate(self.snapshot(rows))['disks']), 1)

    def test_conflicting_disk_sizes_are_rejected(self):
        with self.assertRaises(ValueError):
            self.snapshot([{'name': 'sda', 'size': 0, 'type': 'disk'}, {'name': 'sda', 'size': 10, 'type': 'disk'}])

    def test_invalid_capacities_are_rejected(self):
        for size in (None, -1, True, 1.5, '0x100', 9223372036854775808):
            with self.subTest(size=size), self.assertRaises(ValueError):
                self.snapshot([{'name': 'sda', 'size': size, 'type': 'disk'}])

    def test_empty_capacity_summary_is_valid(self):
        self.assertEqual(validate(self.snapshot([{'name': 'zram0', 'size': 0, 'type': 'disk'}]))['disks'], [])

    def test_missing_release_version_uses_kernel(self):
        with patch.object(probe.platform, 'release', return_value='6.14.0'):
            self.assertEqual(validate(self.snapshot([], 'ID=linux\nVERSION_ID=""\nPRETTY_NAME=""'))['os_version'], '6.14.0')

    def test_real_linux_host(self):
        result = subprocess.run([sys.executable, str(SCRIPT)], capture_output=True, text=True, check=True, timeout=25)
        data = json.loads(result.stdout)
        self.assertGreater(validate(data)['cpu_count'], 0)


if __name__ == '__main__':
    unittest.main()
