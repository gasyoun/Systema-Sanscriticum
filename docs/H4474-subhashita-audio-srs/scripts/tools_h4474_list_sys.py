import sys, re
from urllib.parse import unquote

sys.stdout.reconfigure(encoding='utf-8')

def hrefs(path):
    with open(path, encoding='utf-8') as f:
        xml = f.read()
    return [unquote(h) for h in re.findall(r'<d:href>([^<]*)</d:href>', xml)]

TMP = r'C:\Users\user\AppData\Local\Temp'
for label, path in [('SYS1', TMP + r'\sys1_list.xml'), ('SYS2', TMP + r'\sys2_list.xml')]:
    hs = hrefs(path)
    exts = {}
    for h in hs:
        ext = h.rsplit('.', 1)[-1] if '.' in h.rsplit('/', 1)[-1] else '(dir)'
        exts[ext] = exts.get(ext, 0) + 1
    print(f"=== {label} ({len(hs)} entries) === ext counts: {exts}")
    for h in hs[:8]:
        print(' ', h)
    print('  ...')
    for h in hs[-5:]:
        print(' ', h)
    print()
