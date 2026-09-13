import sys, re
from urllib.parse import unquote

sys.stdout.reconfigure(encoding='utf-8')

def hrefs(path):
    with open(path, encoding='utf-8') as f:
        xml = f.read()
    return [unquote(h) for h in re.findall(r'<d:href>([^<]*)</d:href>', xml)]

for label, path in [('ROOT', '/tmp/root_list.xml'), ('KOCHERGINA', '/tmp/kochergina_list.xml')]:
    hs = hrefs(path)
    print(f"=== {label} ({len(hs)} entries) ===")
    for h in hs:
        print(h)
    print()
