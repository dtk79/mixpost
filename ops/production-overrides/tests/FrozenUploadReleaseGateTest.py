"""Exercise the actual startup guard and stale/mixed asset rejection, without Pro credentials."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

REPO = Path(__file__).resolve().parents[3]
spec = importlib.util.spec_from_file_location('validator', REPO / 'ops/scripts/verify-frozen-upload-release.py')
validator = importlib.util.module_from_spec(spec)
spec.loader.exec_module(validator)
WRAPPER = REPO / 'ops/production-overrides/peachy-start.sh'


class StartupGuardTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.addCleanup(self.temp.cleanup)
        self.gate = self.root / 'gate.json'
        self.record = {
            'schema': 1, 'status': 'passed', 'contract': 'video-upload-recovery-v1',
            'imageId': 'sha256:' + 'a' * 64,
            'suites': {name: {'exitCode': 0, 'checks': count,
                             'testSha256': 'b' * 64, 'logSha256': 'c' * 64}
                       for name, count in [('frontend-network', 13), ('frontend-queue', 3),
                                           ('backend-recovery', 12), ('assets-build', 1),
                                           ('media-temp-permissions', 1)]},
            'files': {}, 'mountInventory': [{'Type': 'bind', 'RW': False,
                                            'Destination': '/usr/local/bin/peachy-start.sh'}],
        }
        for name in validator.SOURCE_FILES + ('package.json', 'package-lock.json', 'vite.config.js'):
            self.file(validator.PRO + '/' + name, 'tested source')
        self.file('/var/www/html/composer.lock', 'locked dependencies')
        self.file('/usr/local/bin/peachy-start.sh', WRAPPER.read_text())
        self.file(validator.ASSETS + '/assets/app.js', 'tested frontend')
        self.file(validator.ASSETS + '/manifest.json', json.dumps({'resources/js/app.js': {'file': 'assets/app.js'}}))

    def file(self, path, content):
        target = self.root / path.lstrip('/')
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(content)
        self.record['files'][path] = validator.sha(target)

    def check(self, passes=False):
        self.gate.write_text(json.dumps(self.record))
        result = subprocess.run(['bash', str(WRAPPER), '--check-release-only', str(self.gate)],
                                env={**os.environ, 'MIXPOST_RELEASE_CHECK_ROOT': str(self.root)},
                                text=True, capture_output=True)
        self.assertEqual(result.returncode, 0 if passes else 1, result.stdout + result.stderr)
        self.assertIn('gate passed' if passes else 'release blocked', result.stdout + result.stderr)

    def test_tested_release_passes_without_starting_artisan(self):
        self.check(passes=True)

    def test_each_missing_suite_blocks(self):
        saved = copy.deepcopy(self.record)
        for name in saved['suites']:
            with self.subTest(name=name):
                self.record = copy.deepcopy(saved)
                del self.record['suites'][name]
                self.check()

    def test_failed_or_incomplete_suite_blocks(self):
        self.record['suites']['backend-recovery']['exitCode'] = 1
        self.check()
        self.record['suites']['backend-recovery']['exitCode'] = 0
        self.record['suites']['backend-recovery']['checks'] = 11
        self.check()

    def test_unpatched_or_changed_source_blocks_even_with_passing_record(self):
        (self.root / (validator.PRO + '/resources/js/bootstrap.js').lstrip('/')).write_text('error.response.status')
        self.check()

    def test_changed_compiled_chunk_blocks(self):
        (self.root / (validator.ASSETS + '/assets/app.js').lstrip('/')).write_text('stale bundle')
        self.check()

    def test_missing_mandatory_fingerprint_blocks(self):
        del self.record['files'][validator.PRO + '/src/Support/ChunkedUpload.php']
        self.check()

    def test_manifest_asset_cannot_be_removed_from_evidence(self):
        del self.record['files'][validator.ASSETS + '/assets/app.js']
        self.check()

    def test_dropped_or_changed_override_blocks(self):
        destination = validator.PRO + '/src/Schedule.php'
        self.record['mountInventory'].append({'Type': 'bind', 'RW': False, 'Destination': destination})
        self.check()
        self.file(destination, 'custom schedule')
        self.check(passes=True)
        (self.root / destination.lstrip('/')).unlink()
        self.check()

    def test_no_archived_or_superseded_contract_bypass(self):
        self.record['status'] = 'superseded-upstream'
        self.check()
        self.record['status'] = 'passed'
        self.record['schema'] = 2
        self.check()

    def test_missing_evidence_blocks(self):
        result = subprocess.run(['bash', str(WRAPPER), '--check-release-only', str(self.gate)], capture_output=True)
        self.assertEqual(result.returncode, 1)

    def test_staged_candidate_does_not_prevent_current_release_or_rollback_startup(self):
        directory = self.root / 'gates'
        directory.mkdir()
        invalid = copy.deepcopy(self.record)
        invalid['files'][validator.PRO + '/src/Support/ChunkedUpload.php'] = 'e' * 64
        (directory / 'new-candidate.json').write_text(json.dumps(invalid))
        (directory / 'current-release.json').write_text(json.dumps(self.record))
        command = ['bash', str(WRAPPER), '--check-release-only', str(directory)]
        env = {**os.environ, 'MIXPOST_RELEASE_CHECK_ROOT': str(self.root)}
        result = subprocess.run(command, env=env, capture_output=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        (directory / 'current-release.json').unlink()
        result = subprocess.run(command, env=env, capture_output=True)
        self.assertEqual(result.returncode, 1)


class RebuildProvenanceTest(unittest.TestCase):
    def test_stale_or_missing_assets_block_but_retained_old_chunks_are_allowed(self):
        with tempfile.TemporaryDirectory() as scratch:
            roots = [Path(scratch) / 'built', Path(scratch) / 'image']
            for root in roots:
                (root / 'assets').mkdir(parents=True)
                (root / 'manifest.json').write_text(json.dumps({'resources/js/app.js': {'file': 'assets/current.js'}}))
                (root / 'assets/current.js').write_text('same fresh build')
            (roots[1] / 'assets/old.js').write_text('retained for open tabs')
            self.assertEqual(validator.compare_build(*roots), ['assets/current.js', 'manifest.json'])
            (roots[1] / 'assets/current.js').write_text('stale bundle')
            with self.assertRaisesRegex(RuntimeError, 'not built from the tested source'):
                validator.compare_build(*roots)
            (roots[1] / 'assets/current.js').unlink()
            with self.assertRaisesRegex(RuntimeError, 'Missing asset'):
                validator.compare_build(*roots)

    def rebuild_fixture(self, output_bytes, mutate_source=False):
        scratch = tempfile.TemporaryDirectory()
        self.addCleanup(scratch.cleanup)
        root = Path(scratch.name)
        package, published, output = root / 'package', root / 'published', root / 'output'
        for path in (package / 'resources/js', published / 'assets', output):
            path.mkdir(parents=True)
        (package / 'resources/js/app.js').write_text('tested source')
        (package / 'vite.config.js').write_text('build configuration')
        manifest = json.dumps({'resources/js/app.js': {'file': 'assets/current.js'}})
        (published / 'manifest.json').write_text(manifest)
        (published / 'assets/current.js').write_text('tested build')
        attempts = []
        def run(*args, **kwargs):
            attempts.append(1)
            built = package / 'resources/dist/vendor/mixpost'
            (built / 'assets').mkdir(parents=True, exist_ok=True)
            (built / 'manifest.json').write_text(manifest)
            (built / 'assets/current.js').write_text(output_bytes[min(len(attempts)-1, len(output_bytes)-1)])
            if mutate_source:
                (package / 'resources/js/app.js').write_text('changed while building')
        return package, published, output, attempts, run

    def test_build_attempts_accept_only_an_exact_match_from_unchanged_inputs(self):
        package, published, output, attempts, run = self.rebuild_fixture(['different hash build', 'tested build'])
        with patch.object(validator, 'run', run):
            _, count, _ = validator.rebuild_and_compare(package, published, {}, output)
        self.assertEqual(count, 2)
        self.assertEqual(len(attempts), 2)

    def test_repeated_stale_builds_are_never_accepted(self):
        package, published, output, attempts, run = self.rebuild_fixture(['stale bundle'])
        with patch.object(validator, 'run', run), self.assertRaisesRegex(RuntimeError, 'No exact source/build match'):
            validator.rebuild_and_compare(package, published, {}, output)
        self.assertEqual(len(attempts), 3)

    def test_changed_inputs_block_even_when_build_bytes_match(self):
        package, published, output, _, run = self.rebuild_fixture(['tested build'], mutate_source=True)
        with patch.object(validator, 'run', run), self.assertRaisesRegex(RuntimeError, 'inputs changed'):
            validator.rebuild_and_compare(package, published, {}, output)


class CutoverPlanTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.proof = Path(self.temp.name) / 'gate.json'
        self.image_id = 'sha256:' + 'd' * 64
        self.live = {
            'Id': 'unchanged-app-container',
            'Config': {'Labels': {
                'com.docker.compose.project.working_dir': '/root/mixpost',
                'com.docker.compose.project.config_files': '/root/mixpost/docker-compose.yml,/root/mixpost/docker-compose.dashboard-overrides.yml',
                'com.docker.compose.project': 'mixpost',
            }},
            'Mounts': [{'Type': 'bind', 'Name': None, 'Source': str(WRAPPER),
                        'Destination': '/usr/local/bin/peachy-start.sh', 'RW': False}],
        }
        self.config = {'services': {
            'mixpost': {'image': 'tested-candidate', 'entrypoint': ['/usr/local/bin/peachy-start.sh'],
                        'environment': {'SETTING': 'preserved'}, 'volumes': ['storage:/app/storage']},
            'mysql': {'image': 'protected-database'}, 'redis': {'image': 'protected-cache'},
        }, 'networks': {'proxy': {'external': True}}}
        self.commands = []
        def run(command):
            self.commands.append(command)
            if command[1:3] == ['image', 'inspect']:
                return json.dumps([{'Id': self.image_id}])
            if command[1] == 'inspect':
                return json.dumps([self.live])
            return json.dumps(self.config)
        self.patcher = patch.object(validator, 'run', run)
        self.patcher.start()
        self.addCleanup(self.patcher.stop)
        self.record = {
            'status': 'passed', 'contract': 'video-upload-recovery-v1', 'imageId': self.image_id,
            'validatorSha256': validator.sha(validator.__file__), 'baselineContainerId': self.live['Id'],
            'mountInventory': copy.deepcopy(self.live['Mounts']),
            'files': {'/usr/local/bin/peachy-start.sh': validator.sha(WRAPPER)},
            'compose': validator.compose_state(self.live)[0],
            'suites': {name: {'exitCode': 0, 'checks': count, 'testSha256': validator.sha(validator.TESTS / filename)}
                       for name, (filename, count) in validator.SUITES.items()},
        }

    def check(self):
        self.proof.write_text(json.dumps(self.record))
        validator.check_plan('tested-candidate', self.proof, 'mixpost-mixpost-1')

    def test_preserves_both_compose_files(self):
        self.check()
        commands = [command for command in self.commands if command[1] == 'compose']
        self.assertTrue(all(command.count('-f') == 2 for command in commands))

    def test_different_image_cannot_reuse_passing_evidence(self):
        self.image_id = 'sha256:' + 'e' * 64
        with self.assertRaisesRegex(RuntimeError, 'exact image'):
            self.check()

    def test_replaced_app_requires_fresh_baseline(self):
        self.live['Id'] = 'another-app-container'
        with self.assertRaisesRegex(RuntimeError, 'Running app changed'):
            self.check()

    def test_dropped_storage_or_changed_environment_or_protected_service_blocks(self):
        saved = copy.deepcopy(self.config)
        mutations = [
            lambda: self.config['services']['mixpost'].pop('volumes'),
            lambda: self.config['services']['mixpost']['environment'].update(SETTING='changed'),
            lambda: self.config['services']['mysql'].update(image='new-database'),
        ]
        for mutate in mutations:
            self.config = copy.deepcopy(saved)
            mutate()
            with self.assertRaisesRegex(RuntimeError, 'Compose configuration changed'):
                self.check()

    def test_changed_test_suite_cannot_reuse_passing_evidence(self):
        self.record['suites']['frontend-network']['testSha256'] = 'f' * 64
        with self.assertRaisesRegex(RuntimeError, 'stale required suite'):
            self.check()

    def test_mount_order_does_not_mask_a_real_inventory_change(self):
        second = {'Type': 'bind', 'Name': None, 'Source': str(WRAPPER), 'Destination': '/test/override', 'RW': False}
        self.live['Mounts'].append(second)
        self.record['files']['/test/override'] = validator.sha(WRAPPER)
        self.record['mountInventory'] = validator.mount_inventory(self.live['Mounts'])
        self.live['Mounts'].reverse()
        self.check()
        self.live['Mounts'][0]['RW'] = True
        with self.assertRaisesRegex(RuntimeError, 'mount inventory changed'):
            self.check()

    def test_candidate_cannot_bypass_startup_guard_entrypoint(self):
        self.config['services']['mixpost']['entrypoint'] = ['/usr/local/bin/start.sh']
        with self.assertRaisesRegex(RuntimeError, 'explicitly invoke'):
            self.check()


if __name__ == '__main__':
    unittest.main()
