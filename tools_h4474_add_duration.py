import sys, re, csv, os, subprocess
from urllib.parse import unquote, quote

sys.stdout.reconfigure(encoding='utf-8')

TMP = r'C:\Users\user\AppData\Local\Temp'
OUT_DIR = r'C:\Users\user\Documents\GitHub\Systema-Sanscriticum-h4474-drain\docs\H4474-subhashita-audio-srs'
MANIFEST = os.path.join(OUT_DIR, 'audio_manifest.tsv')

LOGIN = os.environ['WD_LOGIN']
PASS = os.environ['WD_PASS']

FOLDER_HREF_PREFIX = {
    'Kochergina-Subhashitas': '/Kochergina-Subhashitas/',
    'Subhashitas-Systematic': '/Subhashitas-Systematic/',
    'Subhashitas-Systematic (1)': '/Subhashitas-Systematic%20(1)/',
}

def parse_sizes(xml_path):
    with open(xml_path, encoding='utf-8') as f:
        xml = f.read()
    # crude per-response block parse: href then contentlength within same <d:response>
    blocks = re.findall(r'<d:response>(.*?)</d:response>', xml, re.S)
    sizes = {}
    for b in blocks:
        hm = re.search(r'<d:href>([^<]*)</d:href>', b)
        sm = re.search(r'<d:getcontentlength>([^<]*)</d:getcontentlength>', b)
        if hm and sm:
            sizes[unquote(hm.group(1))] = int(sm.group(1))
    return sizes

sizes = {}
sizes.update(parse_sizes(TMP + r'\kochergina_list.xml'))
sizes.update(parse_sizes(TMP + r'\sys1_list.xml'))
sizes.update(parse_sizes(TMP + r'\sys2_list.xml'))
print(f"parsed sizes for {len(sizes)} hrefs")

rows = []
with open(MANIFEST, encoding='utf-8') as f:
    rows = list(csv.DictReader(f, delimiter='\t'))

# sample a handful of real files across the three folders for a real ffprobe duration/bitrate calibration
sample_targets = []
seen_folder = set()
for r in rows:
    if r['folder'] not in seen_folder:
        seen_folder.add(r['folder'])
        sample_targets.append(r)
    if len(sample_targets) >= 3:
        break

sample_dir = os.path.join(OUT_DIR, '_duration_samples')
os.makedirs(sample_dir, exist_ok=True)
bitrate_kbps = None
calibration = []
for r in sample_targets:
    href = FOLDER_HREF_PREFIX[r['folder']] + quote(r['file'])
    url = 'https://webdav.yandex.ru' + href
    local = os.path.join(sample_dir, f"sample_{len(calibration)}.mp3")
    rc = subprocess.run(['curl', '-s', '-u', f'{LOGIN}:{PASS}', url, '-o', local])
    if os.path.exists(local) and os.path.getsize(local) > 1000:
        probe = subprocess.run(
            ['ffprobe', '-v', 'error', '-show_entries', 'format=duration,bit_rate', '-of',
             'default=noprint_wrappers=1', local],
            capture_output=True, text=True, encoding='utf-8')
        print(r['folder'], r['file'][:40], probe.stdout.strip().replace('\n', ' '))
        dm = re.search(r'duration=([\d.]+)', probe.stdout)
        bm = re.search(r'bit_rate=(\d+)', probe.stdout)
        if dm and bm:
            calibration.append((r['file'], float(dm.group(1)), int(bm.group(1)), sizes.get(r['folder'] + '/' + r['file'] if False else None, None)))

# actual calibration: compute bytes-per-second directly from downloaded sample sizes vs duration
calib2 = []
for r in sample_targets:
    href = FOLDER_HREF_PREFIX[r['folder']] + quote(r['file'])
    key = href
    sz = sizes.get(key)
    local = None
print('bitrate calibration (file, duration_s, size_bytes, computed_bps):')
for i, r in enumerate(sample_targets):
    local = os.path.join(sample_dir, f"sample_{i}.mp3")
    if os.path.exists(local):
        sz = os.path.getsize(local)
        probe = subprocess.run(
            ['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of',
             'default=noprint_wrappers=1:nokey=1', local],
            capture_output=True, text=True)
        dur = probe.stdout.strip()
        try:
            dur_f = float(dur)
            bps = sz / dur_f
            calibration.append(bps)
            print(f"  {r['file'][:40]}  dur={dur_f:.1f}s size={sz} bps={bps:.0f}")
        except ValueError:
            pass

avg_bps = sum(calibration) / len(calibration) if calibration else None
print(f"average bytes/sec across {len(calibration)} samples: {avg_bps}")

# write final manifest with duration column
out_rows = []
for r in rows:
    href = FOLDER_HREF_PREFIX[r['folder']] + r['file']
    size = sizes.get(href)
    if size is None:
        # try with quoting normalized (dict keyed by unquoted href already)
        size = sizes.get(href)
    dur_approx = round(size / avg_bps, 1) if (size and avg_bps) else ''
    r['size_bytes'] = size or ''
    r['duration_sec_approx'] = dur_approx
    out_rows.append(r)

fieldnames = ['folder', 'file', 'raw_num', 'translit_key', 'match_method', 'boehtlingk_num',
              'saying_id', 'iast_head', 'confidence', 'size_bytes', 'duration_sec_approx']
with open(MANIFEST, 'w', encoding='utf-8', newline='') as f:
    w = csv.DictWriter(f, fieldnames=fieldnames, delimiter='\t')
    w.writeheader()
    for r in out_rows:
        w.writerow({k: r.get(k, '') for k in fieldnames})

print('manifest updated with size_bytes + duration_sec_approx:', MANIFEST)
