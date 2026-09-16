import re
import sys

sys.stdout.reconfigure(encoding="utf-8")
sys.stderr.reconfigure(encoding="utf-8")

SRC = "app/Http/Controllers/StudentController.php"
with open(SRC, encoding="utf-8") as f:
    lines = f.readlines()


def slab(a, b):
    return lines[a - 1:b]


use_lines = slab(5, 53)

traits = {
    "StudentScheduleConcerns": (103, 195),
    "StudentDashboardConcerns": (197, 921),
    "StudentCourseContentConcerns": (923, 1385),
    "StudentCertificateConcerns": (1387, 1452),
    "StudentMiscConcerns": (1454, 1510),
}

kept_class_head = slab(1, 102)
kept_class_tail = slab(1511, 1511)


def used_imports(body_text):
    out = []
    for ul in use_lines:
        m = re.match(r"use ([\w\\]+);\s*$", ul.strip())
        if not m:
            continue
        fqcn = m.group(1)
        short = fqcn.split("\\")[-1]
        if re.search(r"\b" + re.escape(short) + r"\b", body_text):
            out.append(fqcn)
    return out


for trait_name, (a, b) in traits.items():
    body = slab(a, b)
    body_text = "".join(body)
    imports = used_imports(body_text)
    out = []
    out.append("<?php\n\n")
    out.append("namespace App\\Http\\Controllers\\Concerns;\n\n")
    for fqcn in imports:
        out.append("use " + fqcn + ";\n")
    out.append("\n")
    out.append("trait " + trait_name + "\n{\n")
    out.extend(body)
    out.append("}\n")
    path = "app/Http/Controllers/Concerns/" + trait_name + ".php"
    with open(path, "w", encoding="utf-8", newline="\n") as f:
        f.writelines(out)
    print("wrote " + path + " (" + str(len(body)) + " lines, " + str(len(imports)) + " imports)")

new_main = []
new_main.extend(kept_class_head)
new_main.append("\n")
for trait_name in traits:
    new_main.append("    use \\App\\Http\\Controllers\\Concerns\\" + trait_name + ";\n")
new_main.append("\n")
new_main.extend(kept_class_tail)

with open(SRC, "w", encoding="utf-8", newline="\n") as f:
    f.writelines(new_main)

print("rewrote " + SRC + " " + str(len(new_main)) + " lines")
