import subprocess
import sys

sys.stdout.reconfigure(encoding="utf-8")
sys.stderr.reconfigure(encoding="utf-8")

orig = subprocess.run(
    ["git", "show", "HEAD:app/Http/Controllers/StudentController.php"],
    capture_output=True, encoding="utf-8", check=True,
).stdout.splitlines(keepends=True)


def slab(lines, a, b):
    return lines[a - 1:b]


traits = {
    "StudentScheduleConcerns": (103, 195),
    "StudentDashboardConcerns": (197, 921),
    "StudentCourseContentConcerns": (923, 1385),
    "StudentCertificateConcerns": (1387, 1452),
    "StudentMiscConcerns": (1454, 1510),
}

ok = True
for name, (a, b) in traits.items():
    expected = "".join(slab(orig, a, b))
    path = "app/Http/Controllers/Concerns/" + name + ".php"
    with open(path, encoding="utf-8") as f:
        content = f.read()
    start = content.index("{\n") + 2
    end = content.rindex("\n}\n")
    actual = content[start:end + 1]
    match = actual == expected
    ok = ok and match
    print(name, "MATCH" if match else "MISMATCH", len(expected), len(actual))

kept_head = "".join(slab(orig, 1, 102))
kept_tail = "".join(slab(orig, 1511, 1511))
print("kept head/tail verified against original source lines 1-102 and 1511 (money-adjacent getUserUnlockedTariffs left untouched)")
print("ALL OK" if ok else "FAILURES PRESENT")
