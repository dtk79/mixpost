#!/usr/bin/env python3
"""Build code-only local-upload overlay on an explicitly verified frozen parent.

This creates an image only. Exact-candidate validation, isolated DB rehearsal,
checkpoint, guarded app-only cutover, and runtime proof are separate.
"""
import argparse
import hashlib
import json
from pathlib import Path
import re
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[2]

def run(args):
    return subprocess.check_output(args, text=True).strip()

def build(base, expected, tag):
    assert re.fullmatch(r'sha256:[0-9a-f]{64}', expected), 'Immutable parent ID required'
    assert run(['docker', 'image', 'inspect', base, '--format', '{{.Id}}']) == expected, 'Frozen parent changed'
    manifest = json.loads((ROOT / 'ops/production-overrides/deployment-manifest.json').read_text())
    sources = {item['host']: item for item in manifest['overrides']}
    recipe = ROOT / 'ops/production-patches/local-uploads/Dockerfile'
    with tempfile.TemporaryDirectory(prefix='mixpost-local-image-') as scratch:
        context = Path(scratch)
        shutil.copyfile(recipe, context / 'Dockerfile')
        hashes = {}
        for name, destination in re.findall(r'^COPY ([\w.-]+) (/[^\s]+)$', recipe.read_text(), re.M):
            item = sources[name]
            assert item['container'] == destination, f'Recipe/manifest mismatch: {name}'
            source = ROOT / item['source']
            shutil.copyfile(source, context / name)
            hashes[destination] = hashlib.sha256(source.read_bytes()).hexdigest()
        subprocess.run(['docker', 'build', '--pull=false', '--network', 'none',
                        '--build-arg', 'BASE=' + base, '-t', tag, str(context)], check=True)
        assert run(['docker', 'image', 'inspect', base, '--format', '{{.Id}}']) == expected, 'Parent changed during build'
        return {'baseImageId': expected, 'imageId': run(['docker', 'image', 'inspect', tag, '--format', '{{.Id}}']),
                'image': tag, 'files': hashes, 'deployed': False}

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base', required=True)
    parser.add_argument('--expected-base-id', required=True)
    parser.add_argument('--tag', required=True)
    args = parser.parse_args()
    print(json.dumps(build(args.base, args.expected_base_id, args.tag), indent=2))
