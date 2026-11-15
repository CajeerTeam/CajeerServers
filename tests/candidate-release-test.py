#!/usr/bin/env python3
"""Real git, package, HTTP and time-bound candidate regression tests (no external services)."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import tarfile
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('candidate',ROOT/'scripts/candidate-release.py')
c=importlib.util.module_from_spec(spec);spec.loader.exec_module(c)

class CandidateTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup)
        self.root=Path(self.temp.name)/'source';self.root.mkdir();self.dest=Path(self.temp.name)/'sealed'
        self.write('VERSION','0.9.9\n')
        for name in c.INPUTS:
            if name=='VERSION': continue
            self.write(name,'{}\n')
        self.write('composer.lock',json.dumps({'packages':[{'name':'vendor/library','version':'1.0.0'}]}))
        for name in ('admin/package-lock.json','packages/ts-sdk/package-lock.json'):
            self.write(name,json.dumps({'packages':{'':{},'node_modules/@scope/library':{'version':'1.2.3'}}}))
        self.write(c.INPUTS[-1],'paramiko==1.0.0 --hash=sha256:'+'a'*64+'\n')
        self.write('core/runtime.php','<?php echo 1;\n');self.write('.gitignore','/vendor/\n/admin/.output/\n/packages/ts-sdk/dist/\n/storage/tmp/\n')
        self.git('init','-q');self.git('add','.');self.git('-c','user.name=Fixture','-c','user.email=fixture@example.invalid','commit','-qm','fixture')
        self.commit=self.git('rev-parse','HEAD').decode().strip()
        self.write('vendor/autoload.php','<?php echo 2;\n');self.write('admin/.output/server/index.mjs','export default 3;\n');self.write('packages/ts-sdk/dist/index.js','export default 4;\n')
        self.write('storage/tmp/release-build.tar.gz','fixture frontend archive')
        self.report()
    def write(self,name,content):
        p=self.root/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_text(content)
    def git(self,*args):return subprocess.check_output(['git',*args],cwd=self.root,stderr=subprocess.DEVNULL)
    def report(self):
        names=[*c.INPUTS,'storage/tmp/release-build.tar.gz',*c.output_names(self.root)]
        self.write('storage/tmp/release-build.sha256',f'version=0.9.9\nsource_commit={self.commit}\n'+''.join(f'{c.digest(self.root/n)}  {n}\n' for n in names))
    def seal(self):return c.seal(self.root,self.dest)
    def deploy(self):
        target=Path(self.temp.name)/'deployment';target.mkdir()
        with tarfile.open(self.dest/'release-candidate.tar.gz') as tar:tar.extractall(target,filter='data')
        return target
    def test_real_seal_and_deploy(self):
        ident=self.seal();target=self.deploy();self.assertEqual(c.verify(target,target/c.MARKER)[0],ident)
        self.assertEqual(c.digest(self.dest/'release-candidate.tar.gz'),(self.dest/'release-candidate.sha256').read_text().split()[0])
        self.assertEqual(len(json.loads((target/c.SBOM).read_text())['components']),3)
        old=c.digest(self.dest/'release-candidate.tar.gz');self.seal();self.assertEqual(c.digest(self.dest/'release-candidate.tar.gz'),old)
    def test_dirty_source_rejected_and_stale_checksum_removed(self):
        self.seal();self.write('core/runtime.php','modified')
        with self.assertRaises(ValueError):self.seal()
        self.assertFalse((self.dest/'release-candidate.sha256').exists())
    def test_assume_unchanged_does_not_hide_source_change(self):
        self.git('update-index','--assume-unchanged','core/runtime.php');self.write('core/runtime.php','tampered')
        with self.assertRaises(ValueError):self.seal()
    def test_archive_must_match_manifest(self):
        self.seal();raw=(self.dest/c.MARKER).read_bytes();data=json.loads(raw)
        package=self.dest/'bad.tar.gz'
        with tarfile.open(package,'w:gz') as tar:tar.add(self.root/'VERSION',arcname='VERSION')
        with self.assertRaises(ValueError):c.verify_archive(package,raw,data)
    def test_vendor_modified_after_build(self):
        self.write('vendor/autoload.php','modified')
        with self.assertRaises(ValueError):self.seal()
    def test_deployment_tampering(self):
        self.seal();target=self.deploy();(target/'core/runtime.php').write_text('modified')
        with self.assertRaises(ValueError):c.verify(target,target/c.MARKER)
    def test_unexpected_runtime_file(self):
        self.seal();target=self.deploy();(target/'core/extra.php').write_text('extra')
        with self.assertRaises(ValueError):c.verify(target,target/c.MARKER)
    def test_extra_output_file(self):
        self.seal();target=self.deploy();(target/'vendor/extra.php').write_text('extra')
        with self.assertRaises(ValueError):c.verify(target,target/c.MARKER)
    def test_tampered_sbom(self):
        self.seal();target=self.deploy();(target/c.SBOM).write_text('{}')
        with self.assertRaises(ValueError):c.verify(target,target/c.MARKER)
    def test_symlink_and_traversal(self):
        (self.root/'core/link').symlink_to('/etc/passwd')
        for name in ('../outside','core/link','/etc/passwd'):
            with self.assertRaises(ValueError):c.safe_path(self.root,name)
    def test_missing_lock(self):
        (self.root/'composer.lock').unlink()
        with self.assertRaises(ValueError):self.seal()
    def test_wrong_commit_report(self):
        self.commit='0'*40;self.report()
        with self.assertRaises(ValueError):self.seal()
    def test_incomplete_report(self):
        self.write('storage/tmp/release-build.sha256',f'version=0.9.9\nsource_commit={self.commit}\n')
        with self.assertRaises(ValueError):self.seal()
    def test_soak_time_and_failure(self):
        now=[0];count=[0]
        def check():count[0]+=1
        def sleep(n):now[0]+=n
        self.assertEqual(c.soak(check,180,60,lambda:now[0],sleep),(4,180))
        def fail():raise ValueError('offline')
        with self.assertRaises(ValueError):c.soak(fail,180,60,lambda:now[0],sleep)
        with self.assertRaises(ValueError):c.soak(check,180,60,lambda:now[0],lambda n:sleep(n+100))
    def test_accept_removes_stale_report(self):
        report=self.dest/'accept.json';self.dest.mkdir();report.write_bytes(c.canonical({'scope':'strict_verification_and_http_soak','status':'passed'}))
        with self.assertRaises(OSError):c.accept(self.root,self.dest/c.MARKER,report,Path(self.temp.name)/'deployment')
        self.assertFalse(report.exists())
    def test_accept_rejects_changed_deployment_before_strict(self):
        self.seal();target=self.deploy();(target/'core/runtime.php').write_text('changed')
        report=self.dest/'accept.json';report.write_bytes(c.canonical({'scope':'strict_verification_and_http_soak','status':'passed'}))
        with self.assertRaisesRegex(ValueError,'Candidate file changed'):c.accept(self.root,self.dest/c.MARKER,report,target)
        self.assertFalse(report.exists())
    def test_accept_refuses_unrelated_report(self):
        report=self.dest/'accept.json';self.dest.mkdir();report.write_text('private file')
        with self.assertRaises(ValueError):c.accept(self.root,self.dest/c.MARKER,report,Path(self.temp.name)/'deployment')
        self.assertEqual(report.read_text(),'private file')
    def test_accept_concurrent_report_lock(self):
        import fcntl
        self.dest.mkdir();report=self.dest/'accept.json'
        with (self.dest/'accept.json.lock').open('w') as lock:
            fcntl.flock(lock,fcntl.LOCK_EX)
            with self.assertRaisesRegex(ValueError,'already running'):
                c.accept(self.root,self.dest/c.MARKER,report,Path(self.temp.name)/'deployment')
    def test_soak_includes_probe_time_in_gap(self):
        now=[0]
        def slow():now[0]+=25
        def sleep(n):now[0]+=n+10
        with self.assertRaisesRegex(ValueError,'gap'):
            c.soak(slow,180,60,lambda:now[0],sleep)
    def test_real_http_probe(self):
        mode=['ok'];identity='a'*64
        class Handler(BaseHTTPRequestHandler):
            def log_message(self,*args):pass
            def do_GET(self):
                if self.path=='/api/v1/system':
                    data={'version':'0.9.9','api_version':'v1','candidate_id':identity if mode[0]!='wrong' else 'b'*64};kind='application/json'
                elif self.path=='/api/v1/ready':data={'status':'ready' if mode[0]!='unready' else 'not_ready'};kind='application/json'
                elif self.path=='/login':
                    if mode[0]=='redirect':self.send_response(302);self.send_header('Location','/login');self.end_headers();return
                    data='<html>login</html>';kind='text/html'
                else:self.send_error(404);return
                self.send_response(200);self.send_header('Content-Type',kind);self.end_headers();self.wfile.write((json.dumps(data) if isinstance(data,dict) else data).encode())
        with ThreadingHTTPServer(('127.0.0.1',0),Handler) as server:
            thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
            try:
                base=f'http://127.0.0.1:{server.server_port}';c.probe(base,'0.9.9',identity)
                for m in ('wrong','unready','redirect'):
                    mode[0]=m
                    with self.assertRaises((ValueError,OSError)):c.probe(base,'0.9.9',identity)
            finally:server.shutdown();thread.join()
    def test_origin_validation(self):
        for url in ('http://example.com','https://user@example.com','https://example.com/path','https://example.com?x=y'):
            with self.assertRaises(ValueError):c.origin(url)

if __name__=='__main__':unittest.main(verbosity=2)
