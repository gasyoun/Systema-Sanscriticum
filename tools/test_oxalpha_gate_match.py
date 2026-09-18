#!/usr/bin/env python3
"""Hermetic fixture battery for tools/oxalpha_gate_match.py (H5062).

Run: python3 tools/test_oxalpha_gate_match.py
Covers: executable match (design §1 patterns), logic-blade rule, doc-only
skip path, decision-5 exclusions, and §3 sensitive-path detection.
No network; uses a throwaway local git repo for the blade-logic branch.
"""
from __future__ import annotations

import json
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from oxalpha_gate_match import classify, is_sensitive, matches  # noqa: E402

FAILURES: list[str] = []


def check(name: str, cond: bool, detail: str = "") -> None:
    if cond:
        print(f"  ok  {name}")
    else:
        FAILURES.append(name)
        print(f"FAIL  {name}  {detail}")


def unit_tests() -> None:
    # §1 include patterns
    for p in ("app/Models/User.php", "routes/web.php", "config/services.php",
              "database/migrations/2026_01_01_000000_create_x.php",
              "tests/Unit/DemoTest.php", "resources/views/x/y.blade.php"):
        check(f"include: {p}", matches(p, [
            "app/**", "routes/**", "config/*.php", "database/migrations/**",
            "resources/views/**/*.blade.php", "tests/**"]))
    check("config subdir is NOT config/*.php", not matches("config/nested/x.php", ["config/*.php"]))
    # §1 decision-5 exclusions
    for p in ("public/vendor/jquery.js", "tools/message-intent-classifier/lib.py",
              "public/app.min.js", "js/app.min.js", "composer.lock", "package-lock.json",
              "docs/notes.md", "CHANGELOG.md", "tests/fixtures/data.json", ".ai_state.md"):
        check(f"exclude: {p}", matches(p, [
            "public/vendor/**", "tools/*/**", "**/*.min.js", "composer.lock",
            "package-lock.json", "yarn.lock", "pnpm-lock.yaml", "bun.lockb", "docs/**",
            "CHANGELOG*", "*/CHANGELOG*", "tests/fixtures/**", "tests/Fixtures/**",
            "*/tests/fixtures/**", "*/tests/Fixtures/**", "tests/**/fixtures/**",
            ".ai_state.md"]))
    check("top-level tools/*.py stays first-party",
          not matches("tools/oxalpha_gate_match.py", ["tools/*/**"]))
    # §3 sensitive patterns (intent globs: star crosses directories)
    for p in ("app/Http/Controllers/ClaimController.php",
              "app/Http/Controllers/Api/PayoutClaimController.php",
              "app/Http/Controllers/Webhooks/TochkaWebhookController.php",
              "app/Services/Payroll/Calculator.php", "app/Models/Payment.php",
              "app/Http/Middleware/VerifyTochkaSignature.php", "config/services.php",
              "config/receivables.php", "deploy.sh",
              "database/migrations/2026_01_01_000000_create_money_ledger.php",
              "database/migrations/2026_01_02_000000_add_access_grants.php"):
        check(f"sensitive: {p}", is_sensitive(p))
    check("User.php not sensitive", not is_sensitive("app/Models/User.php"))


def integration_tests() -> None:
    """Throwaway local git repo: blade logic rule + end-to-end classify()."""
    tmp = Path(tempfile.mkdtemp(prefix="oxalpha-gate-fixture-"))
    try:
        def g(*cmd: str) -> str:
            return subprocess.run(["git", "-C", str(tmp), *cmd],
                                  capture_output=True, text=True, check=True).stdout

        def w(rel: str, content: str) -> None:
            f = tmp / rel
            f.parent.mkdir(parents=True, exist_ok=True)
            f.write_text(content)

        g("init", "-q", "-b", "main")
        g("config", "user.email", "fixture@example.invalid")
        g("config", "user.name", "fixture")
        w("app/Models/User.php", "<?php class User {}\n")
        w("resources/views/plain.blade.php", "<p>static text</p>\n")
        w("resources/views/logic.blade.php", "<p>@if($x) hi @endif</p>\n")
        w("docs/readme.md", "docs\n")
        g("add", "-A")
        g("commit", "-qm", "base")
        base = g("rev-parse", "HEAD").strip()

        # risky slice: money migration + webhook controller + logic blade + regression test
        g("checkout", "-qb", "risky")
        w("database/migrations/2026_09_17_000001_create_payments_mirror.php", "<?php\n")
        w("app/Http/Controllers/Webhooks/TochkaWebhookController.php", "<?php\n")
        w("resources/views/logic.blade.php", "<p>@if($y) changed @auth user @endauth @endif</p>\n")
        w("resources/views/plain.blade.php", "<p>just text changed</p>\n")
        w("tests/Feature/WebhookRegressTest.php", "<?php\n")
        w("docs/more.md", "docs churn\n")
        w("CHANGELOG.md", "churn\n")
        g("add", "-A")
        g("commit", "-qm", "risky")
        head = g("rev-parse", "HEAD").strip()

        res = classify(str(tmp), base, head)
        check("risky: has_executable", res["has_executable"], json.dumps(res))
        check("risky: has_sensitive", res["has_sensitive"])
        check("risky: migration executable",
              "database/migrations/2026_09_17_000001_create_payments_mirror.php" in res["executable"])
        check("risky: webhook controller sensitive",
              "app/Http/Controllers/Webhooks/TochkaWebhookController.php" in res["sensitive"])
        check("risky: logic blade kept",
              "resources/views/logic.blade.php" in res["executable"],
              json.dumps(res["executable"]))
        check("risky: display-only blade dropped",
              "resources/views/plain.blade.php" not in res["executable"])
        check("risky: docs excluded", "docs/more.md" in res["excluded"])
        check("risky: changelog excluded", "CHANGELOG.md" in res["excluded"])
        check("risky: regression test counted",
              "tests/Feature/WebhookRegressTest.php" in res["executable"])

        # ordinary slice: display-only blade + docs only -> skip path, no sensitive
        g("checkout", "-q", "main")
        w("resources/views/plain.blade.php", "<p>text edit only</p>\n")
        w("resources/views/classed.blade.php", "<div @class(['x' => true])>y</div>\n")
        w("docs/again.md", "x\n")
        g("add", "-A")
        g("commit", "-qm", "ordinary")
        head2 = g("rev-parse", "HEAD").strip()
        res2 = classify(str(tmp), base, head2)
        check("ordinary: no executable (skip path)", not res2["has_executable"], json.dumps(res2))
        check("ordinary: no sensitive", not res2["has_sensitive"])
        check("display @class blade NOT logic (design-widening fixed)",
              "resources/views/classed.blade.php" not in res2["executable"], json.dumps(res2))

        # deploy.sh-only slice: NOT executable per §1, but §3-sensitive regardless
        g("checkout", "-q", "main")
        w("deploy.sh", "#!/bin/bash\necho deploy\n")
        g("add", "-A")
        g("commit", "-qm", "deploy change")
        head3 = g("rev-parse", "HEAD").strip()
        res3 = classify(str(tmp), base, head3)
        check("deploy.sh: not executable (§1)", not res3["has_executable"], json.dumps(res3))
        check("deploy.sh: STILL sensitive (§3, P1 fix)",
              res3["has_sensitive"] and "deploy.sh" in res3["sensitive"], json.dumps(res3))
    finally:
        shutil.rmtree(tmp, ignore_errors=True)


if __name__ == "__main__":
    print("== oxalpha_gate_match fixture battery ==")
    unit_tests()
    integration_tests()
    if FAILURES:
        print(f"\n{len(FAILURES)} FAILURE(S): {FAILURES}")
        sys.exit(1)
    print("\nALL MATCH TESTS PASSED")
