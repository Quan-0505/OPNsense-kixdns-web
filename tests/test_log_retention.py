from __future__ import annotations

import importlib.util
import os
from pathlib import Path
from importlib.machinery import SourceFileLoader
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / "src" / "sbin" / "kixdns-log-prune"
spec = importlib.util.spec_from_loader(
    "kixdns_log_prune", SourceFileLoader("kixdns_log_prune", str(SCRIPT))
)
assert spec and spec.loader
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class LogRetentionTests(unittest.TestCase):
    def test_prunes_oldest_archives_and_preserves_active_log(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            oldest = root / "kixdns_20261001.log.0.gz"
            newest = root / "kixdns_20261017.log.0.gz"
            active = root / "kixdns_20261018.log"
            for path, content, stamp in ((oldest, b"a" * 8, 1), (newest, b"b" * 8, 2), (active, b"c" * 5, 3)):
                path.write_bytes(content)
                os.utime(path, (stamp, stamp))

            result = module.enforce_budget(root, max_bytes=15, dry_run=False)

            self.assertEqual(result["bytes_after"], 13)
            self.assertEqual([name for name, _size in result["removed"]], [oldest.name])
            self.assertFalse(oldest.exists())
            self.assertTrue(newest.exists())
            self.assertTrue(active.exists())

    def test_prunes_closed_date_logs_before_preserving_active_log(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            old = root / "kixdns_20261016.log"
            active = root / "kixdns_20261018.log"
            old.write_bytes(b"a" * 8)
            active.write_bytes(b"b" * 5)
            os.utime(old, (1, 1))
            os.utime(active, (2, 2))

            result = module.enforce_budget(root, max_bytes=5)

            self.assertEqual(result["bytes_after"], 5)
            self.assertEqual([name for name, _size in result["removed"]], [old.name])
            self.assertFalse(old.exists())
            self.assertTrue(active.exists())

    def test_dry_run_does_not_remove_archives(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            archive = root / "kixdns_20261001.log.0.gz"
            archive.write_bytes(b"a" * 8)
            result = module.enforce_budget(root, max_bytes=4, dry_run=True)
            self.assertEqual(result["bytes_after"], 0)
            self.assertTrue(archive.exists())

    def test_refuses_when_active_logs_alone_exceed_budget(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            active = root / "kixdns_20261018.log"
            active.write_bytes(b"a" * 8)
            archive = root / "kixdns_20261001.log.0.gz"
            archive.write_bytes(b"b" * 2)
            with self.assertRaises(RuntimeError):
                module.enforce_budget(root, max_bytes=5)
            self.assertTrue(archive.exists())

    def test_missing_log_directory_is_a_noop(self):
        with tempfile.TemporaryDirectory() as tmp:
            result = module.enforce_budget(Path(tmp) / "missing")
            self.assertEqual(result["bytes_after"], 0)
            self.assertEqual(result["removed"], [])


if __name__ == "__main__":
    unittest.main()
