"""Exercise real git patch application, repeat runs, and drift rejection."""
import contextlib
import importlib.util
import io
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

REPO = Path(__file__).resolve().parents[3]
spec = importlib.util.spec_from_file_location('apply_bluesky', REPO / 'ops/scripts/apply-bluesky-publishing-fixes.py')
apply_bluesky = importlib.util.module_from_spec(spec)
spec.loader.exec_module(apply_bluesky)


class PatchApplicationTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.source = self.root / 'enclosing-repo' / 'staged-source'
        self.source.mkdir(parents=True)
        subprocess.run(['git', 'init', '-q', str(self.source.parent)], check=True)
        self.patches = self.root / 'patches'
        self.patches.mkdir()
        for i, name in enumerate(apply_bluesky.NAMES):
            (self.source / f'{i}.php').write_text(f'context\nold{i}\nend\n')
            (self.patches / name).write_text(f'--- a/{i}.php\n+++ b/{i}.php\n@@ -1,3 +1,3 @@\n context\n-old{i}\n+fixed{i}\n end\n')
        self.patcher = patch.object(apply_bluesky, 'PATCHES', self.patches)
        self.patcher.start()
        self.addCleanup(self.patcher.stop)

    def apply(self, check=False):
        with contextlib.redirect_stdout(io.StringIO()):
            apply_bluesky.apply(self.source, check)

    def test_applies_all_fixes_inside_an_unrelated_git_checkout(self):
        self.apply()
        self.assertEqual((self.source / '2.php').read_text(), 'context\nfixed2\nend\n')
        self.assertFalse((self.source.parent / '2.php').exists())

    def test_repeated_application_and_check_preserve_bytes(self):
        self.apply()
        before = [p.read_bytes() for p in sorted(self.source.glob('*.php'))]
        self.apply()
        self.apply(check=True)
        self.assertEqual(before, [p.read_bytes() for p in sorted(self.source.glob('*.php'))])

    def test_mixed_applied_and_pending_fixes_are_supported(self):
        (self.source / '0.php').write_text('context\nfixed0\nend\n')
        self.apply()
        self.apply(check=True)

    def test_upstream_drift_blocks_before_any_file_is_changed(self):
        (self.source / '2.php').write_text('upstream changed its implementation\n')
        with self.assertRaisesRegex(RuntimeError, 'rebase'):
            self.apply()
        self.assertEqual((self.source / '0.php').read_text(), 'context\nold0\nend\n')

    def test_check_only_does_not_modify_unpatched_source(self):
        with self.assertRaisesRegex(RuntimeError, 'missing fix'):
            self.apply(check=True)
        self.assertEqual((self.source / '0.php').read_text(), 'context\nold0\nend\n')


if __name__ == '__main__':
    unittest.main()
