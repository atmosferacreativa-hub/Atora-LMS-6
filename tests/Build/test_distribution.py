"""Exercise the actual CI inspection step against valid and broken ZIPs."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import textwrap
import unittest
import zipfile


ROOT = Path(__file__).resolve().parents[2]
VERSION = "6.27.3"
COMMIT = "5793f8458969614181aaba62c5bae65d3e838300"


class DistributionTest(unittest.TestCase):
    def inspect(self, metadata=None, extra=None, missing=(), raw_json=None):
        workflow = (ROOT / ".github/workflows/ci.yml").read_text()
        step = workflow.split("      - name: Inspect ZIP\n", 1)[1]
        command = textwrap.dedent(
            step.split("        run: |\n", 1)[1].split("\n      - name:", 1)[0]
        )
        info = {"version": VERSION, "commit": COMMIT, "dirty": False}
        info.update(metadata or {})
        files = {
            "atora-lms/atora_lms.php": "<?php\n/**\n * Version: " + VERSION + "\n */\n",
            "atora-lms/readme.txt": "Stable tag: " + VERSION + "\n",
            "atora-lms/build-info.json": raw_json if raw_json is not None else json.dumps(info, indent=2),
        }
        files.update(extra or {})
        for name in missing:
            files.pop(name)
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory)
            (target / "dist").mkdir()
            (target / "scripts").mkdir()
            inspector = ROOT / "scripts/inspect-dist.py"
            if inspector.exists():
                shutil.copy2(inspector, target / "scripts/inspect-dist.py")
            with zipfile.ZipFile(target / "dist" / f"atora-lms-{VERSION}.zip", "w") as archive:
                for name, content in files.items():
                    archive.writestr(name, content)
            return subprocess.run(
                ["bash", "-e", "-o", "pipefail", "-c", command],
                cwd=target, env={**os.environ, "VERSION": VERSION, "EXPECTED_COMMIT": COMMIT},
                capture_output=True, text=True,
            )

    def test_valid_build_metadata_is_accepted(self):
        result = self.inspect()
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def test_compact_json_is_accepted(self):
        result = self.inspect(raw_json=json.dumps({"version": VERSION, "commit": COMMIT, "dirty": False}))
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def test_invalid_metadata_is_rejected(self):
        for metadata in ({"commit": "not-a-sha"}, {"commit": "a" * 40}, {"version": "0.0.0"}, {"dirty": True}, {"dirty": "false"}):
            with self.subTest(metadata=metadata):
                self.assertNotEqual(0, self.inspect(metadata=metadata).returncode)

    def test_malformed_json_is_rejected(self):
        self.assertNotEqual(0, self.inspect(raw_json='{"commit": "' + COMMIT + '",}').returncode)

    def test_missing_required_files_are_rejected(self):
        for name in ("atora_lms.php", "readme.txt", "build-info.json"):
            with self.subTest(name=name):
                self.assertNotEqual(0, self.inspect(missing=("atora-lms/" + name,)).returncode)

    def test_development_files_are_rejected(self):
        for name in ("tests/example.php", ".git/config", "composer.lock", "vendor/autoload.php", ".atora-baseline/db.sql", ".tmp/junit.xml", ".phpunit.result.cache", "demo-audit.demo.example.md"):
            with self.subTest(name=name):
                self.assertNotEqual(0, self.inspect(extra={"atora-lms/" + name: ""}).returncode)

    @unittest.skipUnless(shutil.which("rsync") and shutil.which("zip"), "Distribution tools unavailable")
    def test_build_excludes_local_artifacts(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory)
            (target / "scripts").mkdir()
            for path in (".distignore", "scripts/build-dist.sh", "scripts/build-info.sh"):
                destination = target / path
                destination.write_bytes((ROOT / path).read_bytes())
                if destination.suffix == ".sh":
                    destination.chmod(0o755)
            (target / "atora_lms.php").write_text("<?php\n/**\n * Version: " + VERSION + "\n */\n")
            (target / "readme.txt").write_text("Stable tag: " + VERSION + "\n")
            (target / "safe.php").write_text("<?php // Included plugin file.\n")
            for path in (".atora-baseline/db.sql", ".tmp/junit.xml", ".phpunit.result.cache", "demo-audit.demo.example.json"):
                artifact = target / path
                artifact.parent.mkdir(parents=True, exist_ok=True)
                artifact.write_text("local-only artifact\n")
            subprocess.run(["bash", "scripts/build-dist.sh", VERSION], cwd=target, check=True, capture_output=True, text=True)
            with zipfile.ZipFile(target / "dist" / f"atora-lms-{VERSION}.zip") as archive:
                names = archive.namelist()
            self.assertIn("atora-lms/safe.php", names)
            for forbidden in (".atora-baseline", ".tmp", ".phpunit.result.cache", "demo-audit.demo.example.json"):
                self.assertFalse(any(forbidden in Path(name).parts for name in names), forbidden)

    @unittest.skipUnless(shutil.which("git"), "git unavailable")
    def test_build_info_detects_untracked_files(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory)
            (target / "scripts").mkdir()
            script = target / "scripts/build-info.sh"
            script.write_bytes((ROOT / "scripts/build-info.sh").read_bytes())
            (target / "atora_lms.php").write_text("<?php\n/**\n * Version: " + VERSION + "\n */\n")
            git = ["git", "-c", "user.name=t", "-c", "user.email=t@t", "-c", "commit.gpgsign=false"]
            subprocess.run(["git", "init", "-q"], cwd=target, check=True)
            subprocess.run(git + ["add", "."], cwd=target, check=True)
            subprocess.run(git + ["commit", "-qm", "init"], cwd=target, check=True)

            def dirty():
                out = target / "info.json"
                subprocess.run(["bash", "scripts/build-info.sh", "--out", str(out)], cwd=target, check=True, capture_output=True)
                data = json.loads(out.read_text())
                out.unlink()
                return data["dirty"]

            self.assertFalse(dirty())
            (target / "new-file.php").write_text("<?php\n")
            self.assertTrue(dirty())

    def test_unsafe_paths_are_rejected(self):
        for name in ("../outside.php", "/absolute.php", "atora-lms/../outside.php", "another-plugin/file.php"):
            with self.subTest(name=name):
                self.assertNotEqual(0, self.inspect(extra={name: ""}).returncode)

    def test_version_headers_must_match(self):
        for name, content in (("atora_lms.php", "<?php\n * Version: 0.0.0\n"), ("readme.txt", "Stable tag: 0.0.0\n")):
            with self.subTest(name=name):
                self.assertNotEqual(0, self.inspect(extra={"atora-lms/" + name: content}).returncode)


if __name__ == "__main__":
    unittest.main()
