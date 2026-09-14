import sys, re, csv
from urllib.parse import unquote

sys.stdout.reconfigure(encoding='utf-8')

TMP = r'C:\Users\user\AppData\Local\Temp'
KOSHA_TSV = r'C:\Users\user\Documents\GitHub\kosha\data\subhashita\subhashita_difficulty.tsv'
OUT_DIR = r'C:\Users\user\Documents\GitHub\Systema-Sanscriticum-h4474-drain\docs\H4474-subhashita-audio-srs'

DIACRITICS = {
    'ā': 'a', 'ī': 'i', 'ū': 'u', 'ṛ': 'r', 'ṝ': 'r', 'ḷ': 'l', 'ḹ': 'l',
    'ṃ': 'm', 'ṁ': 'm', 'ḥ': 'h', 'ṅ': 'n', 'ñ': 'n', 'ṭ': 't', 'ḍ': 'd',
    'ṇ': 'n', 'ś': 's', 'ṣ': 's', "'": '', "’": '', '-': '', '_': '',
}

def norm(s):
    s = s.lower()
    for k, v in DIACRITICS.items():
        s = s.replace(k, v)
    s = re.sub(r'[^a-z]', '', s)
    return s

def hrefs(path):
    with open(path, encoding='utf-8') as f:
        xml = f.read()
    return [unquote(h) for h in re.findall(r'<d:href>([^<]*)</d:href>', xml)]

# --- load canonical Böhtlingk index (kosha subhashita_difficulty.tsv, 7537 rows) ---
rows = []
with open(KOSHA_TSV, encoding='utf-8') as f:
    reader = csv.DictReader(f, delimiter='\t')
    for r in reader:
        rows.append(r)

# index by normalized first word of iast_head
first_word_index = {}
for r in rows:
    head = r['iast_head'].strip()
    if not head:
        continue
    fw = norm(head.split()[0])
    if fw:
        first_word_index.setdefault(fw, []).append(r)

def match_by_key(key):
    """Try exact first-word match, then prefix match."""
    if key in first_word_index:
        return first_word_index[key], 'exact-first-word'
    hits = [r for fw, rs in first_word_index.items() if fw.startswith(key) or key.startswith(fw)]
    if hits:
        return hits, 'prefix-first-word'
    return [], 'none'

# --- parse yadisk filenames ---
entries = []  # (folder, filename, extracted_translit_key, raw_num)

# Kochergina-Subhashitas: "Субхашита <Ru> (<Translit>) <Devanagari>.mp3"
for h in hrefs(TMP + r'\kochergina_list.xml'):
    if not h.endswith('.mp3'):
        continue
    fn = h.rsplit('/', 1)[-1]
    m = re.search(r'\(([^)]+)\)', fn)
    translit = m.group(1) if m else ''
    entries.append(('Kochergina-Subhashitas', fn, norm(translit), None))

# Subhashitas-Systematic: "SuN-Name.mp3"
for h in hrefs(TMP + r'\sys1_list.xml'):
    if not h.endswith('.mp3'):
        continue
    fn = h.rsplit('/', 1)[-1]
    m = re.match(r'Su(\d+)-(.+)\.mp3', fn)
    if m:
        entries.append(('Subhashitas-Systematic', fn, norm(m.group(2)), int(m.group(1))))
    else:
        entries.append(('Subhashitas-Systematic', fn, norm(fn), None))

# Subhashitas-Systematic (1): "NN [(MM)] <Ru> (<Translit>) <Devanagari>.mp3"
for h in hrefs(TMP + r'\sys2_list.xml'):
    if not h.endswith('.mp3'):
        continue
    fn = h.rsplit('/', 1)[-1]
    mnum = re.match(r'(\d+)(?:\s*\((\d+)\))?\s+', fn)
    num = int(mnum.group(1)) if mnum else None
    m = re.search(r'\(([^)]+)\)', fn)
    # first parenthesis may be the corrected-number paren if present; find the translit paren (non-numeric)
    parens = re.findall(r'\(([^)]+)\)', fn)
    translit = next((p for p in parens if not p.isdigit()), '')
    entries.append(('Subhashitas-Systematic (1)', fn, norm(translit), num))

# --- match and write manifest ---
import os
os.makedirs(OUT_DIR, exist_ok=True)

manifest_path = os.path.join(OUT_DIR, 'audio_manifest.tsv')
verified = 0
total = 0
with open(manifest_path, 'w', encoding='utf-8', newline='') as out:
    w = csv.writer(out, delimiter='\t')
    w.writerow(['folder', 'file', 'raw_num', 'translit_key', 'match_method', 'boehtlingk_num', 'saying_id', 'iast_head', 'confidence'])
    for folder, fn, key, raw_num in entries:
        total += 1
        hits, method = match_by_key(key) if key else ([], 'no-key')
        if len(hits) == 1:
            r = hits[0]
            conf = 'high' if method == 'exact-first-word' else 'medium'
            if conf == 'high':
                verified += 1
            w.writerow([folder, fn, raw_num or '', key, method, r['num'], r['saying_id'], r['iast_head'], conf])
        elif len(hits) > 1:
            w.writerow([folder, fn, raw_num or '', key, method + f'-ambiguous({len(hits)})', '', '', '', 'low'])
        else:
            w.writerow([folder, fn, raw_num or '', key, method, '', '', '', 'unmatched'])

print(f"total entries: {total}")
print(f"high-confidence (exact first word) matches: {verified}")
print(f"manifest written: {manifest_path}")
