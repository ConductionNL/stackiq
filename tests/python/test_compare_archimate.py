# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
"""Golden test for compare_archimate.py.

Runs the script over the fixtures in tests/fixtures/archimate and compares
exit code, stdout and stderr with tests/python/golden/compare_archimate.json,
case by case. Each case runs under several hash seeds, because the samples of
mismatched identifiers follow set iteration order.

    python3 -m unittest tests/python/test_compare_archimate.py
    RECORD_GOLDEN=1 python3 -m unittest tests/python/test_compare_archimate.py
"""

import json
import os
import subprocess
import sys
import unittest

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
SCRIPT = os.path.join(ROOT, "compare_archimate.py")
FIXTURES = os.path.join(ROOT, "tests", "fixtures", "archimate")
GOLDEN = os.path.join(ROOT, "tests", "python", "golden", "compare_archimate.json")
SEEDS = ("0", "1", "7")

CASES = [
    [],
    ["a.xml"],
    ["a.xml", "a.xml"],
    ["a.xml", "a.xml", "--json"],
    ["a.xml", "b.xml"],
    ["a.xml", "b.xml", "--json"],
    ["b.xml", "a.xml"],
    ["min.xml", "min2.xml"],
    ["min2.xml", "min.xml", "--json"],
    ["min.xml", "min.xml"],
    ["nope.xml", "a.xml"],
    ["a.xml", "nope.xml"],
    ["bad.xml", "a.xml"],
    ["a.xml", "bad.xml"],
    ["--json", "a.xml", "b.xml"],
    ["r1.xml", "r2.xml"],
    ["r2.xml", "r1.xml"],
    ["r1.xml", "r2.xml", "--json"],
    ["r1.xml", "r1.xml"],
    ["big1.xml", "big2.xml"],
    ["big2.xml", "big1.xml"],
    ["big1.xml", "big2.xml", "--json"],
    ["big2.xml", "big1.xml", "--json"],
    ["big1.xml", "big1.xml"],
    ["noorg.xml", "big1.xml"],
    ["big1.xml", "noorg.xml", "--json"],
    ["noorg.xml", "noorg.xml"],
    ["noorg.xml", "min2.xml"],
]


def run_case(args, seed):
    """Run the script once and return its exit code, stdout and stderr."""
    env = dict(os.environ, PYTHONHASHSEED=seed)
    proc = subprocess.run(
        [sys.executable, "-s", "-P", SCRIPT, *args],
        cwd=FIXTURES,
        env=env,
        capture_output=True,
        text=True,
        timeout=60,
        check=False,
    )
    return {"exit": proc.returncode, "stdout": proc.stdout, "stderr": proc.stderr}


def case_key(args, seed):
    """Golden key of one case."""
    return f"seed={seed} " + (" ".join(args) or "(no arguments)")


class CompareArchimateGoldenTest(unittest.TestCase):
    """compare_archimate.py output matches the recorded golden."""

    def test_golden(self):
        actual = {case_key(a, s): run_case(a, s) for a in CASES for s in SEEDS}
        if os.environ.get("RECORD_GOLDEN"):
            os.makedirs(os.path.dirname(GOLDEN), exist_ok=True)
            with open(GOLDEN, "w", encoding="utf-8") as handle:
                json.dump(actual, handle, indent=1, sort_keys=True)
                handle.write("\n")
        with open(GOLDEN, encoding="utf-8") as handle:
            expected = json.load(handle)
        self.assertEqual(sorted(expected), sorted(actual))
        for key, result in actual.items():
            with self.subTest(case=key):
                self.assertEqual(expected[key], result)


if __name__ == "__main__":
    unittest.main()
