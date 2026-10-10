#!/usr/bin/env python3
"""Compare retained mounts and registered image files with pristine old/new code.

Exports contain code only: live bind files by basename; image/old/new roots mirror
container paths, or use app/, bootstrap/, routes/, pro/ as audit exports.
"""
import argparse
import hashlib
import json
from pathlib import Path


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest() if path.is_file() else None


def relative(target):
    return target.replace('/var/www/html/vendor/inovector/mixpost-pro-team/', 'pro/').replace('/var/www/html/', '').lstrip('/')


def audit(mounts, live, old, new, manifest=None, image_source=None):
    rows = []
    inventory = json.loads(Path(mounts).read_text())
    for mount in inventory:
        if mount['Type'] != 'bind':
            continue
        name = Path(mount['Source']).name
        target = mount['Destination']
        rows.append(row(name, target, digest(Path(live) / name), old, new, 'bind-mount', not mount['RW']))
    if manifest:
        entries = json.loads(Path(manifest).read_text())['overrides']
        baked = [entry for entry in entries if entry.get('delivery') == 'frozen-image']
        if baked and not image_source:
            raise RuntimeError('Registered image customizations require --image-source code-only export')
        destinations = {entry['container'] for entry in rows}
        for entry in baked:
            if entry['container'] in destinations:
                raise RuntimeError('Image-only customization unexpectedly has a mount; review effective delivery')
            target = entry['container']
            rows.append(row(entry['host'], target, digest(Path(image_source) / relative(target)), old, new, 'frozen-image', True))
    if any(not entry['readOnly'] or not entry['liveSha256'] for entry in rows):
        raise RuntimeError('Incomplete or writable customization source inventory')
    return rows


def row(name, target, live_hash, old, new, delivery, read_only):
    path = relative(target)
    old_hash, new_hash = digest(Path(old) / path), digest(Path(new) / path)
    return {'host': name, 'container': target, 'delivery': delivery, 'readOnly': read_only,
            'liveSha256': live_hash, 'oldUpstreamSha256': old_hash, 'newUpstreamSha256': new_hash,
            'review': 'custom-file' if old_hash is None else 'target-removed' if new_hash is None
                      else 'upstream-unchanged' if old_hash == new_hash else 'rebase-required'}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    for field in ['mounts', 'live', 'old', 'new', 'output']:
        parser.add_argument('--' + field, required=True)
    parser.add_argument('--manifest', help='Reviewed deployment manifest, including image-baked customizations')
    parser.add_argument('--image-source', help='Code-only export of the exact running frozen image')
    args = parser.parse_args()
    result = audit(args.mounts, args.live, args.old, args.new, args.manifest, args.image_source)
    Path(args.output).write_text(json.dumps(result, indent=2) + '\n')
    print(f'{len(result)} customizations; {sum(x["review"] == "rebase-required" for x in result)} upstream changes; {sum(x["review"] == "target-removed" for x in result)} removed targets')
