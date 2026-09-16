import glob
import re
import sys

sys.stdout.reconfigure(encoding="utf-8")
sys.stderr.reconfigure(encoding="utf-8")

for path in sorted(glob.glob("app/Http/Controllers/Concerns/*.php")):
    with open(path, encoding="utf-8") as f:
        content = f.read()
    print("==", path, "==")
    for m in re.finditer(r"^use ([\w\\]+);\s*$", content, re.MULTILINE):
        fqcn = m.group(1)
        short = fqcn.split("\\")[-1]
        count = len(re.findall(r"\b" + re.escape(short) + r"\b", content))
        if count <= 1:
            print("  POSSIBLY UNUSED:", fqcn, "count=", count)
