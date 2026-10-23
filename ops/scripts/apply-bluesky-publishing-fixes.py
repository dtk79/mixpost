#!/usr/bin/env python3
"""Apply reviewed Bluesky patches to staged effective Pro source; refuse upstream drift.

Run before building the frozen candidate, after rebasing existing mounted
customizations onto its exact source. Never point this at a running release.
"""
import argparse
import os
from pathlib import Path
import subprocess

PATCHES = Path(__file__).resolve().parents[1] / 'production-patches'
NAMES = ('bluesky-mentions-7.1.4.patch', 'bluesky-link-previews-7.1.4.patch',
         'bluesky-thread-failures-7.1.4.patch')


def git_apply(source, patch, *options):
    env = {key: value for key, value in os.environ.items()
           if key not in ('GIT_DIR', 'GIT_WORK_TREE', 'GIT_INDEX_FILE')}
    env['GIT_CEILING_DIRECTORIES'] = str(source.parent)
    return subprocess.run(['git', 'apply', '--unsafe-paths', *options, str(patch)],
                          cwd=source, env=env, capture_output=True, text=True)


def apply(source, check=False):
    source = source.resolve(strict=True)
    pending = []
    for name in NAMES:
        patch = PATCHES / name
        if git_apply(source, patch, '--reverse', '--check').returncode == 0:
            continue
        if check or git_apply(source, patch, '--check').returncode:
            raise RuntimeError(f'{name}: missing fix or changed source; rebase and rerun the Bluesky behavior suite')
        pending.append(patch)
    # Preflight every patch before modifying any staged file. Each patch owns a
    # separate file, so applying one cannot invalidate another patch's context.
    for patch in pending:
        result = git_apply(source, patch)
        if result.returncode:
            raise RuntimeError(f'{patch.name}: apply failed; discard this staging tree and rebase')
    print(f'Bluesky fixes verified; {len(pending)} patches applied')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source', type=Path, required=True, help='Staged effective Pro package directory')
    parser.add_argument('--check', action='store_true', help='Require all patches to be present without writing')
    args = parser.parse_args()
    try:
        apply(args.source, args.check)
    except (RuntimeError, OSError) as error:
        raise SystemExit(f'BLOCKED: {error}')
