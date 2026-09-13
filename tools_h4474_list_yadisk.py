import sys, re
from urllib.parse import unquote

sys.stdout.reconfigure(encoding='utf-8')

def hrefs(path):
    with open(path, encoding='utf-8') as f:
        xml = f.read()
    return [unquote(h) for h in re.findall(r'<d:href>([^<]*)</d:href>', xml)]

TMP = r'C:\Users\user\AppData\Local\Temp'
for label, path in [('ROOT', TMP + r'\root_list.xml'), ('KOCHERGINA', TMP + r'\kochergina_list.xml')]:
    hs = hrefs(path)
    print(f"=== {label} ({len(hs)} entries) ===")
    for h in hs:
        print(h)
    print()
