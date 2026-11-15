#!/usr/bin/env python3
"""Materialize only portable in-bundle symlinks before sealing production outputs.

Nitro and package tools may emit relative links. The distributed release must
be self-contained and contain only regular files, so copy their referents after
checking the entire path stays inside the output. Never follow an external link.
"""
import argparse
import os
from pathlib import Path
import shutil
import stat
import sys
import tempfile

MAX_FILES = 250_000
MAX_BYTES = 8 * 1024**3


def normalize(root):
    if root.is_symlink() or not root.is_dir():
        raise ValueError('Expected a real build output directory')
    root = root.resolve()
    # No expensive copy for ordinary regular-file output; still reject special files.
    linked = False
    for directory, dirs, files in os.walk(root, followlinks=False):
        for name in dirs + files:
            path = Path(directory) / name
            mode = path.lstat().st_mode
            if stat.S_ISLNK(mode): linked = True
            elif not (stat.S_ISREG(mode) or stat.S_ISDIR(mode)):
                raise ValueError('Special file in build output')
    if not linked: return False
    count = size = 0
    with tempfile.TemporaryDirectory(prefix='.normalize-release-', dir=root.parent) as work:
        temp = Path(work) / 'output'
        old = Path(work) / 'original'
        def copy(src, dst, ancestors):
            nonlocal count, size
            # Resolve every symlink, including chains and links to directories.
            # Absolute paths to inside the bundle are safe to materialize too.
            try: resolved = src.resolve(strict=True)
            except (OSError, RuntimeError) as exc: raise ValueError('Broken or cyclic output symlink') from exc
            if not resolved.is_relative_to(root):
                raise ValueError('Nonportable output symlink')
            info = resolved.stat()
            if stat.S_ISDIR(info.st_mode):
                inode = (info.st_dev, info.st_ino)
                if inode in ancestors: raise ValueError('Cyclic output directory symlink')
                dst.mkdir()
                for child in sorted(resolved.iterdir()):
                    copy(child, dst / child.name, ancestors | {inode})
                os.chmod(dst, stat.S_IMODE(info.st_mode))
            elif stat.S_ISREG(info.st_mode):
                count += 1; size += info.st_size
                if count > MAX_FILES or size > MAX_BYTES:
                    raise ValueError('Materialized build output exceeds safety limits')
                shutil.copyfile(resolved, dst)
                os.chmod(dst, stat.S_IMODE(info.st_mode))
            else:
                raise ValueError('Special file in build output')
        copy(root, temp, set())
        # Build output is not live deployment state. Roll back the old tree if
        # publication fails. A crash forces a fresh build and no success report.
        os.rename(root, old)
        try: os.rename(temp, root)
        except BaseException:
            os.rename(old, root)
            raise
    return True


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('directories', nargs='+', type=Path)
    args = parser.parse_args()
    try:
        for directory in args.directories:
            changed = normalize(directory)
            print(f'{directory}: {"materialized portable links" if changed else "regular-file output"}')
    except (OSError, ValueError) as exc:
        print('Output normalization failed:', str(exc), file=sys.stderr)
        return 1
    return 0

if __name__ == '__main__': sys.exit(main())
