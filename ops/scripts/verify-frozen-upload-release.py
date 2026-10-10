#!/usr/bin/env python3
"""Test the actual frozen image, then issue a fingerprinted startup gate.

Run on the Docker host. Never starts the app, reads its environment, mounts
production storage, or contacts a production database/bucket. No skip switches.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
from datetime import datetime, timezone

APP = '/var/www/html'
PRO = APP + '/vendor/inovector/mixpost-pro-team'
ASSETS = APP + '/public/vendor/mixpost'
REPO = Path(__file__).resolve().parents[2]
TESTS = REPO / 'ops/production-overrides/tests'
WRAPPER = REPO / 'ops/production-overrides/peachy-start.sh'
SOURCE_FILES = (
    'resources/js/bootstrap.js', 'resources/js/Composables/useNotifications.js',
    'resources/js/Composables/useChunkedUpload.js',
    'resources/js/Components/Media/UploadMedia.vue',
    'resources/js/Components/Media/UploadProgressPanel.vue',
    'resources/js/Components/Media/UploadProgressItem.vue',
    'src/Support/ChunkedUpload.php',
)
SUITES = {
    'frontend-network': ('VideoUploadNetworkErrorsTest.mjs', 13),
    'frontend-queue': ('VideoUploadQueueRecoveryTest.mjs', 3),
    'backend-recovery': ('ChunkedUploadRecoveryTest.php', 12),
    'assets-build': ('VideoUploadAssetsTest.mjs', 1),
}


def sha(path):
    with Path(path).open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def run(args, *, env=None, cwd=None, log=None):
    result = subprocess.run(args, cwd=cwd, env=env, text=True,
                            stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    if log:
        Path(log).write_text(result.stdout)
    if result.returncode:
        # Build logs remain private; never print candidate configuration.
        raise RuntimeError(f'{args[0]} failed ({result.returncode}); see {log or "command output"}')
    return result.stdout.strip()


def asset_files(directory):
    manifest = json.loads((directory / 'manifest.json').read_text())
    if not manifest or 'resources/js/app.js' not in manifest:
        raise RuntimeError('Missing app entrypoint in asset manifest')
    files = {'manifest.json'}
    for key, entry in manifest.items():
        for field in ('file', 'css', 'assets'):
            values = entry.get(field, [])
            values = [values] if isinstance(values, str) else values
            for name in values:
                if Path(name).is_absolute() or '..' in Path(name).parts:
                    raise RuntimeError('Invalid asset path')
                if not (directory / name).is_file():
                    raise RuntimeError(f'Missing asset: {name}')
                files.add(name)
        for field in ('imports', 'dynamicImports'):
            for key2 in entry.get(field, []):
                if key2 not in manifest:
                    raise RuntimeError(f'Missing manifest import: {key2}')
    return sorted(files)


def compare_build(built, published):
    # Compare active files only: retained older chunks are allowed for open tabs.
    files = asset_files(built)
    if files != asset_files(published):
        raise RuntimeError('Image assets were not built from the tested source (manifest differs)')
    for name in files:
        if sha(built / name) != sha(published / name):
            raise RuntimeError(f'Image assets were not built from the tested source: {name}')
    return files


def frontend_inputs(package):
    paths = list(package.glob('package*.json')) + [package / 'vite.config.js']
    for name in ('resources', 'src'):
        paths += [p for p in (package / name).rglob('*')
                  if p.is_file() and not p.is_relative_to(package / 'resources/dist')]
    return {str(p.relative_to(package)): sha(p) for p in sorted(paths)}


def rebuild_and_compare(package, published, env, output):
    # Some compiler runs assign different chunk hashes. Never normalize or
    # ignore them: accept only an exact match from unchanged tested inputs.
    inputs = frontend_inputs(package)
    built = package / 'resources/dist/vendor/mixpost'
    mismatch = None
    for attempt in range(1, 4):
        run(['npm', 'run', 'build'], cwd=package, env=env, log=output / f'build-{attempt}.log')
        if inputs != frontend_inputs(package):
            raise RuntimeError('Frontend inputs changed during the build')
        shutil.copyfile(built / 'manifest.json', output / f'rebuilt-manifest-{attempt}.json')
        shutil.copyfile(published / 'manifest.json', output / 'image-manifest.json')
        try:
            return compare_build(built, published), attempt, inputs
        except RuntimeError as error:
            mismatch = error
    raise RuntimeError(f'No exact source/build match after 3 builds: {mismatch}')


def mount_inventory(mounts):
    # Docker emits mounts from a map; order is not a configuration change.
    return sorted([{key: mount.get(key) for key in ('Type', 'Name', 'Source', 'Destination', 'RW')}
                   for mount in mounts], key=lambda mount: mount['Destination'])


def compose_state(container):
    labels = container['Config']['Labels']
    directory = labels['com.docker.compose.project.working_dir']
    files = labels['com.docker.compose.project.config_files'].split(',')
    command = ['docker', 'compose', '--project-directory', directory,
               '--project-name', labels['com.docker.compose.project']]
    for file in files:
        command += ['-f', file]
    config = json.loads(run(command + ['config', '--format', 'json']))
    if config['services']['mixpost'].get('entrypoint') != ['/usr/local/bin/peachy-start.sh']:
        raise RuntimeError('Compose must explicitly invoke the mounted startup guard')
    app_image = config['services']['mixpost'].pop('image')
    digest = hashlib.sha256(json.dumps(config, sort_keys=True).encode()).hexdigest()
    return {'files': files, 'sha256IgnoringAppImage': digest}, app_image


def check_plan(image, proof, baseline):
    record = json.loads(proof.read_text())
    image_id = json.loads(run(['docker', 'image', 'inspect', image]))[0]['Id']
    if record.get('status') != 'passed' or record.get('contract') != 'video-upload-recovery-v1' or record.get('imageId') != image_id:
        raise RuntimeError('Missing passing evidence for this exact image')
    if record.get('validatorSha256') != sha(__file__):
        raise RuntimeError('Validator changed since evidence was issued; rerun')
    for name, (filename, count) in SUITES.items():
        suite = record.get('suites', {}).get(name, {})
        if suite.get('exitCode') != 0 or suite.get('checks', 0) < count or suite.get('testSha256') != sha(TESTS / filename):
            raise RuntimeError(f'Missing/stale required suite: {name}')
    live = json.loads(run(['docker', 'inspect', baseline]))[0]
    if record.get('baselineContainerId') != live['Id']:
        raise RuntimeError('Running app changed since validation; rerun against the current baseline')
    current_compose, configured_image = compose_state(live)
    if current_compose != record.get('compose'):
        raise RuntimeError('Effective Compose configuration changed beyond the app image; preserve both Compose files, environment, services, networks and mounts')
    if json.loads(run(['docker', 'image', 'inspect', configured_image]))[0]['Id'] != image_id:
        raise RuntimeError('Compose does not reference the exact tested candidate image')
    inventory = mount_inventory(live['Mounts'])
    if inventory != record.get('mountInventory'):
        raise RuntimeError('Live mount inventory changed; rerun')
    for mount in live['Mounts']:
        if mount['Type'] == 'bind' and sha(mount['Source']) != record['files'].get(mount['Destination']):
            raise RuntimeError('Mounted customization changed since validation; rerun')
    print(f'PASS: cutover configuration and current tests match {image_id}; database rehearsal/checkpoint and post-cutover checks remain required')


def verify(image, output, baseline):
    output = output.resolve()
    output.mkdir(parents=True, exist_ok=True, mode=0o700)
    proof = output / 'upload-gate.json'
    # Failed reruns must not leave a previous passing gate in this output folder.
    proof.unlink(missing_ok=True)
    image_id = json.loads(run(['docker', 'image', 'inspect', image]))[0]['Id']
    live = json.loads(run(['docker', 'inspect', baseline]))[0]
    compose, _ = compose_state(live)
    mounts = live['Mounts']
    wrapper_mount = next((m for m in mounts if m['Destination'] == '/usr/local/bin/peachy-start.sh'), None)
    if not wrapper_mount or wrapper_mount['Type'] != 'bind' or wrapper_mount['RW']:
        raise RuntimeError('Required read-only startup guard mount is missing')
    forbidden = [m for m in mounts if m['Type'] == 'bind' and
                 (m['Destination'].endswith('/manifest.json') or
                  '/public/vendor/mixpost/assets/' in m['Destination'] or
                  '/resources/dist/vendor/mixpost/' in m['Destination'])]
    if forbidden:
        raise RuntimeError('Pinned frontend asset/manifest bind mounts are forbidden')
    for mount in mounts:
        if mount['Type'] == 'bind' and (mount['RW'] or not Path(mount['Source']).is_file()):
            raise RuntimeError('Review writable/directory bind mounts before validating a frozen release')
    temporary_container = None
    with tempfile.TemporaryDirectory(prefix='mixpost-release-') as scratch:
        work = Path(scratch)
        package, published = work / 'package', work / 'published'
        package.mkdir()
        published.mkdir()
        try:
            temporary_container = run(['docker', 'create', '--network', 'none', '--entrypoint', 'true', image_id])
            # Deliberately do not copy app .env, bootstrap caches, or storage.
            for name in ('resources', 'src', 'package.json', 'package-lock.json', 'vite.config.js'):
                run(['docker', 'cp', f'{temporary_container}:{PRO}/{name}', str(package / name)])
            run(['docker', 'cp', f'{temporary_container}:{ASSETS}/.', str(published)])
            run(['docker', 'cp', f'{temporary_container}:{APP}/composer.lock', str(work / 'composer.lock')])
            snapshots = work / 'mounts'
            snapshots.mkdir()
            bindings = []
            fingerprints = {}
            inventory = mount_inventory(mounts)
            for i, mount in enumerate(mounts):
                if mount['Type'] != 'bind':
                    continue
                original = WRAPPER if mount == wrapper_mount else Path(mount['Source'])
                snapshot = snapshots / str(i)
                shutil.copyfile(original, snapshot)
                destination = mount['Destination']
                bindings += ['--mount', f'type=bind,src={snapshot},dst={destination},readonly']
                fingerprints[destination] = sha(snapshot)
                if destination.startswith(PRO + '/'):
                    target = package / destination.removeprefix(PRO + '/')
                    target.parent.mkdir(parents=True, exist_ok=True)
                    shutil.copyfile(snapshot, target)
                if destination.startswith(ASSETS + '/'):
                    target = published / destination.removeprefix(ASSETS + '/')
                    target.parent.mkdir(parents=True, exist_ok=True)
                    shutil.copyfile(snapshot, target)
            env = {**os.environ, 'MIXPOST_FRONTEND_SOURCE_DIR': str(package), 'HUSKY': '0'}
            print('Installing locked candidate dependencies...', flush=True)
            run(['npm', 'ci', '--ignore-scripts', '--no-audit', '--no-fund'], cwd=package, env=env, log=output / 'npm-ci.log')
            suites = {}
            for name in ('frontend-network', 'frontend-queue'):
                filename, count = SUITES[name]
                print(f'Checking {name}...', flush=True)
                log = output / f'{name}.log'
                result = run(['node', '--test', str(TESTS / filename)], env=env, log=log)
                if f'# pass {count}\n' not in result + '\n' or '# skipped 0' not in result:
                    raise RuntimeError(f'{name}: expected all {count} checks without skips; see {log}')
                suites[name] = {'exitCode': 0, 'checks': count, 'testSha256': sha(TESTS / filename), 'logSha256': sha(log)}
            print('Checking backend recovery in an isolated candidate runtime...', flush=True)
            test = TESTS / 'ChunkedUploadRecoveryTest.php'
            log = output / 'backend-recovery.log'
            result = run(['docker', 'run', '--rm', '--network', 'none', '--read-only',
                          '--tmpfs', '/tmp:rw', '--entrypoint', 'php', *bindings,
                          '--mount', f'type=bind,src={test},dst=/tmp/recovery-test.php,readonly',
                          '-e', f'CHUNKED_UPLOAD_SOURCE_PATH={PRO}/src/Support/ChunkedUpload.php',
                          image_id, '/tmp/recovery-test.php'], log=log)
            if sum(line.startswith('PASS ') for line in result.splitlines()) != 12:
                raise RuntimeError('Expected all 12 backend checks')
            suites['backend-recovery'] = {'exitCode': 0, 'checks': 12, 'testSha256': sha(test), 'logSha256': sha(log)}
            print('Rebuilding the complete frontend and comparing image assets...', flush=True)
            files, build_attempts, inputs = rebuild_and_compare(package, published, env, output)
            log = output / 'assets-build.log'
            env['MIXPOST_ASSET_DIR'] = str(published)
            test = TESTS / 'VideoUploadAssetsTest.mjs'
            run(['node', str(test)], env=env, log=log)
            suites['assets-build'] = {'exitCode': 0, 'checks': 1, 'testSha256': sha(test), 'logSha256': sha(log)}
            for name in SOURCE_FILES + ('package.json', 'package-lock.json', 'vite.config.js'):
                fingerprints[PRO + '/' + name] = sha(package / name)
            fingerprints[APP + '/composer.lock'] = sha(work / 'composer.lock')
            for name in files:
                fingerprints[ASSETS + '/' + name] = sha(published / name)
            # Recheck original mounts: a concurrent override change invalidates this validation.
            for mount in mounts:
                if mount['Type'] == 'bind' and mount != wrapper_mount:
                    if sha(mount['Source']) != fingerprints[mount['Destination']]:
                        raise RuntimeError('Mounted override changed during validation; rerun')
            record = {
                'schema': 1, 'status': 'passed', 'contract': 'video-upload-recovery-v1',
                'imageId': image_id, 'imageReference': image,
                'at': datetime.now(timezone.utc).isoformat(),
                'validatorSha256': sha(__file__), 'suites': suites, 'files': fingerprints,
                'mountInventory': inventory, 'baselineContainerId': live['Id'], 'compose': compose,
                'buildAttempts': build_attempts,
                'frontendInputSha256': hashlib.sha256(json.dumps(inputs, sort_keys=True).encode()).hexdigest(),
                'scope': 'upload-contract-only; database rehearsal and live checks also required',
            }
            staged = output / '.upload-gate.json.tmp'
            staged.write_text(json.dumps(record, indent=2) + '\n')
            os.replace(staged, proof)
            print(f'PASS: exact image {image_id}; upload startup gate: {proof}', flush=True)
        finally:
            if temporary_container:
                try:
                    subprocess.run(['docker', 'rm', '-f', temporary_container], stdout=subprocess.DEVNULL, check=True)
                except subprocess.CalledProcessError:
                    proof.unlink(missing_ok=True)
                    raise RuntimeError('Failed to remove validation container; gate withdrawn')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--image', required=True, help='Existing exact frozen candidate image (never pulls latest)')
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument('--output', type=Path, help='Private audit directory; a failed run removes the gate')
    group.add_argument('--check-plan', type=Path, help='Recheck a passing gate against the exact candidate and effective Compose before cutover')
    parser.add_argument('--baseline-container', default='mixpost-mixpost-1')
    args = parser.parse_args()
    try:
        if args.check_plan:
            check_plan(args.image, args.check_plan, args.baseline_container)
        else:
            verify(args.image, args.output, args.baseline_container)
    except (RuntimeError, OSError, ValueError, KeyError, StopIteration) as error:
        raise SystemExit(f'BLOCKED: {error}')
