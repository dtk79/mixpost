#!/usr/bin/env python3
"""Ensure preservation checks reject omissions and drift, not just current files."""
import hashlib
import importlib.util
import json
from pathlib import Path
import shutil
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[3]
spec = importlib.util.spec_from_file_location('register', ROOT / 'ops/scripts/check-customization-register.py')
register = importlib.util.module_from_spec(spec)
spec.loader.exec_module(register)

class PreservationRegisterTest(unittest.TestCase):
    def setUp(self):
        self.scratch = tempfile.TemporaryDirectory()
        self.root = Path(self.scratch.name)
        for directory in ['ops/production-overrides', 'ops/production-patches', 'ops/production-network', 'docs/customizations']:
            shutil.copytree(ROOT / directory, self.root / directory)
        value = json.loads((ROOT/'docs/customizations/modifications.json').read_text())
        for entry in value['overrides']:
            destination = self.root/entry['documentation']
            if not destination.exists():
                destination.parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(ROOT/entry['documentation'], destination)
    def tearDown(self):
        self.scratch.cleanup()
    def mutate(self, callback):
        path = self.root / 'docs/customizations/modifications.json'
        value = json.loads(path.read_text()); callback(value); path.write_text(json.dumps(value))
    def reject(self):
        with self.assertRaises(AssertionError): register.validate(self.root)
    def test_omitted_override(self):
        self.mutate(lambda value: value['overrides'].pop()); self.reject()
    def test_changed_destination(self):
        self.mutate(lambda value: value['overrides'][0].update(container='/wrong')); self.reject()
    def test_wrong_delivery(self):
        self.mutate(lambda value: value['overrides'][-1].update(delivery='bind-mount')); self.reject()
    def test_missing_source(self):
        (self.root / 'ops/production-overrides/LocalMediaStaging.php').unlink(); self.reject()
    def test_missing_image_recipe_mapping(self):
        path = self.root / 'ops/production-patches/local-uploads/Dockerfile'
        path.write_text(path.read_text().replace('COPY LocalMediaStaging.php ', 'COPY unregistered.php ')); self.reject()
    def test_missing_acceptance(self):
        self.mutate(lambda value: value['overrides'][0].update(acceptance='')); self.reject()
    def test_missing_infrastructure(self):
        (self.root / 'ops/production-overrides/peachy-start.sh').unlink(); self.reject()
    def test_missing_network_configuration(self):
        (self.root / 'ops/production-network/mixpost-upload-http1.yml').unlink(); self.reject()
    def test_unregistered_active_network_configuration(self):
        self.mutate(lambda value: value['infrastructureFiles'].remove(value['networkExperiments'][0]['composeSource'])); self.reject()
    def test_missing_network_retirement_contract(self):
        self.mutate(lambda value: value['networkExperiments'][0].update(retirement='')); self.reject()
    def make_gate(self):
        register_data = json.loads((self.root/'docs/customizations/modifications.json').read_text())
        files = {entry['container']: hashlib.sha256((self.root/entry['source']).read_bytes()).hexdigest() for entry in register_data['overrides']}
        files['/usr/local/bin/peachy-start.sh'] = hashlib.sha256((self.root/'ops/production-overrides/peachy-start.sh').read_bytes()).hexdigest()
        gate = self.root/'candidate.json'; gate.write_text(json.dumps({'status':'passed','files':files})); return gate
    def test_exact_candidate_source_match(self): register.validate(self.root, self.make_gate())
    def test_rejects_live_only_source_drift(self):
        gate = self.make_gate(); (self.root/'ops/production-overrides/ManagesInstagramResources.php').write_text('old source')
        with self.assertRaises(AssertionError): register.validate(self.root, gate)
    def test_rejects_missing_candidate_fingerprint(self):
        gate = self.make_gate(); value = json.loads(gate.read_text()); value['files'].pop('/usr/local/bin/peachy-start.sh'); gate.write_text(json.dumps(value))
        with self.assertRaises(AssertionError): register.validate(self.root, gate)
    def test_missing_checklist(self):
        (self.root / 'docs/customizations/update-hardening.md').unlink(); self.reject()

spec = importlib.util.spec_from_file_location('audit', ROOT / 'ops/upgrades/audit-overrides.py')
audit = importlib.util.module_from_spec(spec); spec.loader.exec_module(audit)

class ImagePreservationAuditTest(unittest.TestCase):
    def setUp(self):
        self.scratch = tempfile.TemporaryDirectory(); self.root = Path(self.scratch.name)
        self.mounts = self.root / 'mounts.json'; self.mounts.write_text('[]')
        self.manifest = self.root / 'manifest.json'
        self.manifest.write_text(json.dumps({'overrides': [{'delivery':'frozen-image', 'host':'Helper.php', 'container':'/var/www/html/vendor/inovector/mixpost-pro-team/src/Support/Helper.php'}]}))
        self.path = self.root / 'image/pro/src/Support/Helper.php'; self.path.parent.mkdir(parents=True); self.path.write_text('retained source')
    def tearDown(self): self.scratch.cleanup()
    def run_audit(self, image=None):
        return audit.audit(self.mounts, self.root/'live', self.root/'old', self.root/'new', self.manifest, image)
    def test_includes_unmounted_image_customization(self):
        rows = self.run_audit(self.root/'image')
        self.assertEqual(len(rows), 1); self.assertEqual(rows[0]['delivery'], 'frozen-image'); self.assertIsNotNone(rows[0]['liveSha256'])
    def test_requires_explicit_image_export(self):
        with self.assertRaises(RuntimeError): self.run_audit()
    def test_rejects_missing_image_source(self):
        self.path.unlink()
        with self.assertRaises(RuntimeError): self.run_audit(self.root/'image')
    def test_rejects_unexpected_mount(self):
        self.mounts.write_text(json.dumps([{'Type':'bind','Source':'/host/Helper.php','Destination':'/var/www/html/vendor/inovector/mixpost-pro-team/src/Support/Helper.php','RW':False}]))
        with self.assertRaises(RuntimeError): self.run_audit(self.root/'image')

if __name__ == '__main__': unittest.main()
