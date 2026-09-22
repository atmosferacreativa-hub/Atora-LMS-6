#!/usr/bin/env python3
"""Validate a distribution ZIP and its provenance without extracting it."""
import json
from pathlib import PurePosixPath
import re
import sys
import zipfile


def inspect(archive_path, version, commit):
    if not re.fullmatch(r"[0-9a-f]{40}", commit):
        raise ValueError("Expected commit must be a full Git SHA")
    forbidden = {".git", ".claude", "tests", "docs", "vendor", "composer.json", "composer.lock", "phpunit.xml"}
    with zipfile.ZipFile(archive_path) as archive:
        names = archive.namelist()
        if len(names) != len(set(names)):
            raise ValueError("Duplicate ZIP entries")
        for name in names:
            path = PurePosixPath(name)
            if path.is_absolute() or ".." in path.parts or "\\" in name or not path.parts or path.parts[0] != "atora-lms":
                raise ValueError("Unexpected ZIP path: " + name)
            if forbidden.intersection(path.parts):
                raise ValueError("Development file in ZIP: " + name)
        corrupt = archive.testzip()
        if corrupt:
            raise ValueError("Corrupt ZIP entry: " + corrupt)
        info = json.loads(archive.read("atora-lms/build-info.json"))
        if not isinstance(info, dict):
            raise ValueError("Build metadata must be a JSON object")
        if info.get("commit") != commit:
            raise ValueError("Build commit does not match the checked-out commit")
        if info.get("version") != version:
            raise ValueError("Build version does not match the requested version")
        if info.get("dirty") is not False:
            raise ValueError("Distribution must declare dirty=false")
        for name, pattern in (
            ("atora_lms.php", r"^\s*\* Version:\s*(\S+)\s*$"),
            ("readme.txt", r"^Stable tag:\s*(\S+)\s*$"),
        ):
            content = archive.read("atora-lms/" + name).decode("utf-8")
            match = re.search(pattern, content, re.MULTILINE)
            if not match or match.group(1) != version:
                raise ValueError("Version mismatch in " + name)
    print(f"Distribution verified: version={version} commit={commit}")


if __name__ == "__main__":
    if len(sys.argv) != 4:
        sys.exit("Usage: inspect-dist.py ZIP VERSION EXPECTED_COMMIT")
    try:
        inspect(*sys.argv[1:])
    except (OSError, ValueError, KeyError, zipfile.BadZipFile, RuntimeError) as error:
        sys.exit("Distribution inspection failed: " + str(error))
