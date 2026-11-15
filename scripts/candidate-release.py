#!/usr/bin/env python3
"""Seal and verify a specific release build; run fail-closed full acceptance on staging."""
import argparse
import gzip
import fcntl
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import stat
import subprocess
import sys
import tarfile
import tempfile
import time
from urllib.request import build_opener, HTTPRedirectHandler, Request
from urllib.parse import urlsplit, quote

ROOT = Path(__file__).resolve().parents[1]
OUTPUTS = ('vendor', 'admin/.output', 'packages/ts-sdk/dist')
INPUTS = ('VERSION','release.json','composer.json','composer.lock','admin/package.json',
          'admin/package-lock.json','packages/ts-sdk/package.json','packages/ts-sdk/package-lock.json',
          '.nvmrc','core/Connector/agents/requirements-linux-py312.lock')
MARKER = '.release-candidate.json'
SBOM = 'release-candidate.cdx.json'
RUNTIME_TREES = ('core','app','api','routes','config','public','installer','modules','components','templates','migrations','languages','bin')
HEX = re.compile(r'[0-9a-f]{64}\Z')


def digest(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def canonical(data):
    return (json.dumps(data, sort_keys=True, separators=(',', ':'), ensure_ascii=True) + '\n').encode()


def safe_path(root, name):
    p = PurePosixPath(name)
    if p.is_absolute() or str(p) != name or not p.parts or '..' in p.parts or '\\' in name:
        raise ValueError('Unsafe manifest path')
    target = root.joinpath(*p.parts)
    if any(x.is_symlink() for x in [target, *target.parents] if x != root and root in x.parents):
        raise ValueError('Symlinks are not supported in candidate files')
    if not target.is_file():
        raise ValueError('Missing candidate file: ' + name)
    return target


def entry(root, name):
    p = safe_path(root, name)
    return {'sha256': digest(p), 'size': p.stat().st_size, 'executable': bool(p.stat().st_mode & stat.S_IXUSR)}


def git(root, *args):
    return subprocess.check_output(['git', *args], cwd=root)


def clean_source(root):
    if git(root, 'status', '--porcelain', '--untracked-files=normal').strip():
        raise ValueError('Candidate requires a clean committed checkout')
    commit = git(root, 'rev-parse', 'HEAD').decode().strip()
    # Inspect committed Git objects, not just git status (which can hide assume-unchanged files).
    names=[]
    for row in git(root,'ls-tree','-r','-z','HEAD').split(b'\0'):
        if not row: continue
        header, rawname=row.split(b'\t',1)
        mode,kind,oid=header.decode().split()
        name=rawname.decode()
        if kind!='blob' or mode not in ('100644','100755'):
            raise ValueError('Candidate source must contain regular committed files')
        if name in ('.env', MARKER, SBOM) or (name.startswith('storage/') and not name.endswith('.gitkeep')):
            raise ValueError('Runtime state must not be tracked')
        path=safe_path(root,name)
        size=path.stat().st_size
        h=hashlib.new('sha1' if len(oid)==40 else 'sha256')
        h.update(f'blob {size}\0'.encode())
        with path.open('rb') as stream:
            for block in iter(lambda:stream.read(1024*1024),b''):h.update(block)
        if h.hexdigest()!=oid or bool(path.stat().st_mode & stat.S_IXUSR)!=(mode=='100755'):
            raise ValueError('Working file differs from commit: '+name)
        names.append(name)
    if not names or not all(x in names for x in INPUTS):
        raise ValueError('All reviewed inputs must be committed')
    return commit, names


def output_names(root):
    names = []
    for folder in OUTPUTS:
        p = root / folder
        if not p.is_dir() or p.is_symlink():
            raise ValueError('Missing build output: ' + folder)
        for f in p.rglob('*'):
            if f.is_symlink():
                raise ValueError('Symlink in build output')
            if f.is_file():
                names.append(f.relative_to(root).as_posix())
        if not any(n.startswith(folder + '/') for n in names):
            raise ValueError('Empty build output: ' + folder)
    return names


def check_build(root, commit, version):
    report = root / 'storage/tmp/release-build.sha256'
    archive = root / 'storage/tmp/release-build.tar.gz'
    text = report.read_text()
    lines = text.splitlines()
    if lines[:2] != ['version=' + version, 'source_commit=' + commit]:
        raise ValueError('Build report does not identify the current commit')
    hashes = {}
    for line in lines:
        match = re.fullmatch(r'([0-9a-f]{64})  (.+)', line)
        if match:
            h, name = match.groups()
            if name in hashes or digest(safe_path(root, name)) != h:
                raise ValueError('Build report hash mismatch: ' + name)
            hashes[name] = h
    expected = set(INPUTS) | {'storage/tmp/release-build.tar.gz'} | set(output_names(root))
    if not expected <= hashes.keys():
        raise ValueError('Build report is incomplete')
    return digest(archive)


def verify(root, marker):
    if marker.is_symlink(): raise ValueError('Unsafe candidate manifest')
    if marker.stat().st_size > 16 * 1024 * 1024: raise ValueError('Candidate manifest too large')
    raw = marker.read_bytes()
    data = json.loads(raw)
    if data.get('format') != 1 or raw != canonical(data) or data.get('version') != (root/'VERSION').read_text().strip():
        raise ValueError('Invalid candidate manifest or version mismatch')
    files = data.get('files')
    if not isinstance(files, dict) or not set(INPUTS) <= files.keys() or not re.fullmatch(r'[0-9a-f]{40,64}', data.get('commit','')):
        raise ValueError('Incomplete candidate manifest')
    for name, expected in files.items():
        if entry(root, name) != expected:
            raise ValueError('Candidate file changed: ' + name)
    if set(output_names(root)) != {n for n in files if n.startswith(tuple(x+'/' for x in OUTPUTS))}:
        raise ValueError('Unexpected build output files')
    for folder in RUNTIME_TREES:
        for p in (root/folder).rglob('*'):
            if p.is_symlink() or (p.is_file() and p.relative_to(root).as_posix() not in files):
                raise ValueError('Unexpected runtime file: ' + p.relative_to(root).as_posix())
    if digest(safe_path(marker.parent, SBOM)) != data.get('sbom_sha256'):
        raise ValueError('Candidate SBOM changed')
    return hashlib.sha256(raw).hexdigest(), data


def verify_deployment(root, expected_id=None):
    """Verify a standalone installation, not a build tree with dev dependencies."""
    identity, data = verify(root, root / MARKER)
    if expected_id is not None and identity != expected_id:
        raise ValueError('Deployment candidate identity mismatch')
    allowed = set(data['files']) | {MARKER, SBOM, '.env'}
    # Never traverse mutable state (backup, logs, uploads). Its shipped .gitkeep
    # files are already authenticated by verify(). Everything else is immutable.
    for directory, dirs, names in os.walk(root, followlinks=False):
        base = Path(directory)
        for name in list(dirs):
            path = base / name
            rel = path.relative_to(root).as_posix()
            if path.is_symlink():
                raise ValueError('Unexpected deployment symlink: ' + rel)
            if rel == 'storage':
                dirs.remove(name)
        for name in names:
            path = base / name
            rel = path.relative_to(root).as_posix()
            if path.is_symlink() or not path.is_file() or rel not in allowed:
                raise ValueError('Unexpected deployment file: ' + rel)
    return identity, data


def deployment_check(root):
    # Configuration is loaded from the real deployment's .env, not a test DB
    # or APP_KEY exported by the acceptance build host.
    env = {'PATH': os.environ.get('PATH', '/usr/bin:/bin'),
           'HOME': os.environ.get('HOME', '/tmp'), 'LANG': 'C.UTF-8'}
    subprocess.run(['php', 'bin/cajeer', 'release:check', '--strict'], cwd=root,
                   env=env, timeout=600, check=True)


def atomic_write(path, content):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temp = tempfile.mkstemp(dir=path.parent, prefix='.candidate-')
    try:
        with os.fdopen(fd, 'wb') as out:
            out.write(content); out.flush(); os.fsync(out.fileno())
        os.replace(temp, path)
    finally:
        if os.path.exists(temp): os.unlink(temp)


def locked_sbom(root, version):
    """Inventory the reviewed lock resolutions (including dev/optional dependencies)."""
    components = {}
    def add(ecosystem, name, version, optional=False, hashes=None):
        if not isinstance(name,str) or not name or not isinstance(version,str) or not version:
            raise ValueError('Invalid locked component')
        purl = 'pkg:'+ecosystem+'/'+quote(name, safe='/')+'@'+quote(version,safe='')
        component = {'type':'library','name':name,'version':version,'purl':purl,
                     'bom-ref':purl,'scope':'optional' if optional else 'required'}
        if hashes: component['hashes'] = hashes
        if purl not in components or components[purl]['scope']=='optional': components[purl]=component
    lock=json.loads((root/'composer.lock').read_text())
    for key in ('packages','packages-dev'):
        for pkg in lock.get(key,[]): add('composer',pkg['name'],pkg['version'],key=='packages-dev')
    for filename in ('admin/package-lock.json','packages/ts-sdk/package-lock.json'):
        for path,pkg in json.loads((root/filename).read_text())['packages'].items():
            if not path: continue
            name=pkg.get('name') or path.rsplit('node_modules/',1)[-1]
            add('npm',name,pkg['version'],bool(pkg.get('dev') or pkg.get('optional')))
    for line in (root/INPUTS[-1]).read_text().splitlines():
        if not line.strip() or line.startswith('#'): continue
        match=re.fullmatch(r'([^=]+)==([^ ]+) --hash=sha256:([0-9a-f]{64})',line)
        if not match: raise ValueError('Invalid Python lock entry')
        name,ver,h=match.groups();add('pypi',name.lower(),ver,False,[{'alg':'SHA-256','content':h}])
    return canonical({'bomFormat':'CycloneDX','specVersion':'1.6','version':1,
                      'metadata':{'component':{'type':'application','name':'CajeerServers','version':version}},
                      'components':[components[k] for k in sorted(components)]})


def verify_archive(package, raw, data):
    seen=set()
    allowed=set(data['files']) | {MARKER,SBOM}
    with tarfile.open(package,'r:gz') as tar:
        for member in tar:
            if not member.isfile() or member.name in seen:
                raise ValueError('Invalid candidate archive member')
            name=PurePosixPath(member.name)
            if name.is_absolute() or str(name)!=member.name or not name.parts or '..' in name.parts or '\\' in member.name or member.name not in allowed:
                raise ValueError('Unexpected or unsafe candidate archive member')
            seen.add(member.name)
            expected=hashlib.sha256(raw).hexdigest() if member.name==MARKER else data['sbom_sha256'] if member.name==SBOM else data['files'][member.name]['sha256']
            if member.name in data['files']:
                info=data['files'][member.name]
                if member.size!=info['size'] or bool(member.mode & stat.S_IXUSR)!=info['executable']:
                    raise ValueError('Archive metadata mismatch')
            with tar.extractfile(member) as stream:
                if hashlib.file_digest(stream,'sha256').hexdigest()!=expected:
                    raise ValueError('Archive hash mismatch')
    if seen != allowed:
        raise ValueError('Candidate archive is incomplete')


def seal(root, destination):
    destination.mkdir(parents=True, exist_ok=True)
    with (destination/'.seal.lock').open('a') as lock:
        try: fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError: raise ValueError('Another candidate seal is active') from None
        return _seal(root,destination)


def _seal(root, destination):
    destination.mkdir(parents=True, exist_ok=True)
    checksum = destination / 'release-candidate.sha256'
    checksum.unlink(missing_ok=True)
    commit, names = clean_source(root)
    version = (root/'VERSION').read_text().strip()
    build_hash = check_build(root, commit, version)
    names = sorted(set(names + output_names(root)))
    bom=locked_sbom(root,version)
    data = {'format':1,'version':version,'commit':commit,'frontend_archive_sha256':build_hash,
            'sbom_sha256':hashlib.sha256(bom).hexdigest(),
            'files':{n:entry(root,n) for n in names}}
    raw = canonical(data)
    if len(raw) > 16 * 1024 * 1024: raise ValueError('Candidate manifest exceeds HTTP identity limit')
    destination.mkdir(parents=True, exist_ok=True)
    marker = destination / MARKER
    package = destination / 'release-candidate.tar.gz'
    checksum = destination / 'release-candidate.sha256'
    # No old success checksum survives a failed re-seal.
    checksum.unlink(missing_ok=True)
    fd, tmp = tempfile.mkstemp(dir=destination, prefix='.candidate-')
    try:
        with os.fdopen(fd,'wb') as out, gzip.GzipFile(fileobj=out, mode='wb', mtime=0, filename='') as zipped, tarfile.open(fileobj=zipped, mode='w|') as tar:
            for name in names:
                p = safe_path(root,name)
                info = tar.gettarinfo(str(p), arcname=name)
                info.uid=info.gid=0; info.uname=info.gname=''; info.mtime=0
                info.mode=0o755 if data['files'][name]['executable'] else 0o644
                with p.open('rb') as stream: tar.addfile(info,stream)
            import io
            for name,content in ((MARKER,raw),(SBOM,bom)):
                info=tarfile.TarInfo(name);info.size=len(content);info.mode=0o644;tar.addfile(info,io.BytesIO(content))
        # Detect source or output changes while packaging. Verify archive bytes too.
        for name in names:
            if entry(root,name)!=data['files'][name]: raise ValueError('Files changed during seal')
        if clean_source(root)[0]!=commit: raise ValueError('Commit changed during seal')
        verify_archive(tmp,raw,data)
        os.replace(tmp,package)
        atomic_write(marker,raw)
        atomic_write(destination/SBOM,bom)
        atomic_write(checksum,(digest(package)+'  '+package.name+'\n').encode())
    finally:
        if os.path.exists(tmp): os.unlink(tmp)
    return hashlib.sha256(raw).hexdigest()


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs): return None


def origin(value):
    u=urlsplit(value)
    if u.scheme not in ('http','https') or not u.hostname or u.username is not None or u.password is not None or u.path not in ('','/') or u.query or u.fragment:
        raise ValueError('Expected bare staging origin')
    if u.scheme!='https' and u.hostname not in ('localhost','127.0.0.1','::1'):
        raise ValueError('Remote staging requires HTTPS')
    return value.rstrip('/')


def probe(base, version, identity):
    opener=build_opener(NoRedirect())
    for path, kind in (('/api/v1/system','application/json'),('/api/v1/ready','application/json'),('/login','text/html')):
        with opener.open(Request(base+path,headers={'Accept':kind}),timeout=10) as response:
            if response.status!=200 or kind not in response.headers.get('Content-Type',''):
                raise ValueError('Unexpected staging response: '+path)
            body=response.read(2*1024*1024+1)
            if len(body)>2*1024*1024: raise ValueError('Staging response too large')
        if path.endswith('/system'):
            data=json.loads(body)
            if data.get('version')!=version or data.get('api_version')!='v1' or data.get('candidate_id')!=identity:
                raise ValueError('Staging candidate identity mismatch')
        elif path.endswith('/ready'):
            if json.loads(body).get('status')!='ready': raise ValueError('Staging not ready')
        elif b'<html' not in body.lower(): raise ValueError('Staging login is not HTML')


def soak(check, seconds, interval, clock=time.monotonic, sleep=time.sleep):
    start=clock(); last_start=start; count=0
    while True:
        now=clock()
        if now-last_start>interval+30: raise ValueError('Soak monitoring gap exceeded')
        last_start=now
        check(); count+=1
        end=clock()
        if end-now>30: raise ValueError('Soak probe exceeded time budget')
        elapsed=end-start
        if elapsed>=seconds: return count, elapsed
        sleep(min(interval,seconds-elapsed))


def accept(root, marker, report, deployment_root):
    # A separate lock covers the entire test/soak/publication lifetime.
    report = report.absolute()
    if report.is_symlink() or report == root or report == deployment_root or root in report.parents or deployment_root in report.parents:
        raise ValueError('Unsafe acceptance report path')
    if marker.parent == report.parent and report.name in (MARKER,SBOM,'release-candidate.tar.gz','release-candidate.sha256','.seal.lock'):
        raise ValueError('Acceptance report must not replace a candidate artifact')
    report.parent.mkdir(parents=True, exist_ok=True)
    fd = os.open(str(report)+'.lock', os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'rb+') as lock:
        try: fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError: raise ValueError('Acceptance is already running') from None
        return _accept(root, marker, report, deployment_root)


def _accept(root, marker, report, deployment_root):
    # Never overwrite source, deployed files, or any candidate artifact with evidence.
    if root in report.parents or deployment_root in report.parents or marker.parent==report.parent and report.name in (MARKER,SBOM,'release-candidate.tar.gz','release-candidate.sha256'):
        raise ValueError('Acceptance report must be outside source, deployment and candidate artifacts')
    # Remove only previous acceptance evidence, never an unrelated file.
    # Only success creates a new report.
    if report.exists():
        if not report.is_file() or report.is_symlink() or report.stat().st_size > 65536:
            raise ValueError('Unsafe prior acceptance report')
        old=json.loads(report.read_bytes())
        if not isinstance(old,dict) or old.get('scope') not in ('strict_verification_and_http_soak','strict_verification_deployment_capacity_and_http_soak'):
            raise ValueError('Refusing to overwrite an unrelated file')
        report.unlink()
    identity,data=verify(root,marker)
    package=marker.parent/'release-candidate.tar.gz'
    package_hash=digest(package)
    if (marker.parent/'release-candidate.sha256').read_text()!=package_hash+'  '+package.name+'\n':
        raise ValueError('Candidate archive checksum mismatch')
    verify_archive(package,marker.read_bytes(),data)
    if clean_source(root)[0]!=data['commit']: raise ValueError('Acceptance checkout commit differs')
    if deployment_root==root or verify_deployment(deployment_root,identity)[0]!=identity:
        raise ValueError('Staging deployment files differ from candidate')
    if os.environ.get('E2E_ISOLATED_STAGING')!='1': raise ValueError('Disposable staging confirmation required')
    base=origin(os.environ.get('E2E_BASE_URL',''))
    if origin(os.environ.get('CAJEER_SMOKE_URL',''))!=base: raise ValueError('HTTP and browser staging origins must match')
    check=lambda:probe(base,data['version'],identity)
    check()
    deployment_check(deployment_root)
    env=dict(os.environ,CAJEER_CANDIDATE_ID=identity,PYTHONDONTWRITEBYTECODE="1")
    started=int(time.time())
    subprocess.run(['php','scripts/verify-release.php','--strict','--deployment-root='+str(deployment_root)],cwd=root,env=env,check=True)
    # Strict tests exercise the sealed Nuxt output and actual deployment.
    check()
    count,elapsed=soak(check,72*3600,60)
    if clean_source(root)[0]!=data['commit'] or verify(root,marker)[0]!=identity or digest(package)!=package_hash:
        raise ValueError('Candidate changed during acceptance')
    deployment_check(deployment_root)
    if verify_deployment(deployment_root,identity)[0]!=identity:
        raise ValueError('Staging deployment changed during acceptance')
    atomic_write(report,canonical({'format':2,'status':'passed','scope':'strict_verification_deployment_capacity_and_http_soak','promotion_ready':False,'candidate_id':identity,'commit':data['commit'],
                                  'version':data['version'],'archive_sha256':package_hash,'strict_exit':0,'deployment_exit':0,'capacity_profile':'100k-assets/1k-targets/1m-samples/100k-events/50-clients/p95-500ms','soak_seconds':int(elapsed),'soak_checks':count,
                                  'started_at':started,'finished_at':int(time.time())}))


def main():
    p=argparse.ArgumentParser();p.add_argument('command',choices=['seal','verify','verify-deployment','accept']);p.add_argument('--root',type=Path,default=ROOT)
    p.add_argument('--directory',type=Path);p.add_argument('--manifest',type=Path);p.add_argument('--report',type=Path)
    p.add_argument('--deployment-root',type=Path,help='Accessible separate staging release directory')
    p.add_argument('--expected-id',help='Trusted manifest SHA-256 for verify')
    a=p.parse_args();root=a.root.resolve()
    try:
        if a.command=='seal': print('Candidate ID:',seal(root,(a.directory or root/'storage/tmp/candidate').resolve()))
        elif a.command=='verify':
            identity=verify(root,a.manifest or root/MARKER)[0]
            if a.expected_id is not None and identity!=a.expected_id: raise ValueError('Candidate identity mismatch')
            print('Candidate ID:',identity)
        elif a.command=='verify-deployment':
            if not a.expected_id: raise ValueError('Trusted --expected-id required')
            print('Candidate ID:',verify_deployment(root,a.expected_id)[0])
        else:
            if not a.manifest or not a.report or not a.deployment_root: raise ValueError('accept requires --manifest, --deployment-root and --report')
            if a.manifest.resolve()==a.report.resolve(): raise ValueError('Manifest and report must differ')
            accept(root,a.manifest.resolve(),a.report.parent.resolve()/a.report.name,a.deployment_root.resolve());print('Candidate acceptance passed')
    except (OSError,ValueError,KeyError,TypeError,subprocess.CalledProcessError) as error:
        print('Candidate verification failed:',type(error).__name__,str(error) if isinstance(error,ValueError) else '',file=sys.stderr);return 1
    return 0

if __name__=='__main__': sys.exit(main())
