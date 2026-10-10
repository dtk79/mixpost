#!/usr/bin/env python3
"""Fail CI when a production override has no preservation record or mapping drifts."""
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]

def validate(root=ROOT):
    manifest = json.loads((root / 'ops/production-overrides/deployment-manifest.json').read_text())
    register = json.loads((root / 'docs/customizations/modifications.json').read_text())
    assert register['schema'] == 1
    entries = register['overrides']
    assert len({entry['source'] for entry in entries}) == len(entries), 'Duplicate register source'
    expected = {entry['source']: entry for entry in manifest['overrides']}
    actual = {entry['source']: entry for entry in entries}
    assert actual.keys() == expected.keys(), 'Undocumented or stale production override'
    for source, entry in actual.items():
        assert entry['container'] == expected[source]['container'], f'Destination drift: {source}'
        assert entry.get('delivery', 'bind-mount') == expected[source].get('delivery', 'bind-mount'), f'Delivery drift: {source}'
        assert (root / source).is_file(), f'Missing source: {source}'
        assert entry['status'] == 'required' and entry['acceptance'] and entry['retirement'], source
        assert (root / entry['documentation']).is_file(), f'Missing documentation: {source}'
    for source in register['imagePatchFiles']:
        assert (root / source).is_file(), f'Missing image patch: {source}'
    for source in register['infrastructureFiles']:
        assert (root / source).is_file(), f'Missing infrastructure: {source}'
    assert (root / register['releaseChecklist']).is_file(), 'Missing release checklist'
    recipe = (root / 'ops/production-patches/local-uploads/Dockerfile').read_text()
    import re
    copied = dict(re.findall(r'^COPY ([\w.-]+) (/[^\s]+)$', recipe, re.M))
    for entry in manifest['overrides']:
        if entry.get('delivery') == 'frozen-image':
            assert copied.get(entry['host']) == entry['container'], f'Unbuilt image override: {entry["source"]}'
    print(f'PASS preservation register: {len(entries)} overrides and {len(register["imagePatchFiles"])} image patches')

if __name__ == '__main__':
    validate()
