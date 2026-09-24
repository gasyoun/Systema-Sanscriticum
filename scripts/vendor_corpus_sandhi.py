#!/usr/bin/env python3
"""Bake kosha's corpus-sandhi dataset into a frozen page layer for /reading/sandhi.

H4718 (census A13, reports/CROSSWALK_CANDIDATE_MAPPINGS_CENSUS_14-09-2026.md in
Uprava). kosha `corpus-sandhi` (data/sandhi/corpus_sandhi.tsv, CC BY-SA 4.0) ranks
13,012 distinct sandhi rules by frequency over 707,936 sandhi events in 41 DCS
texts. This script bakes the head of that ranking — every rule up to the
--coverage cutoff of all corpus sandhi (default 90%) — into
resources/data/corpus_sandhi/corpus_sandhi_top.json, which
App\\Http\\Controllers\\CorpusSandhiController renders.

Reads the TSV straight out of kosha's `origin/main` (not its working tree, which
can be stale) and records the blob SHA + commit it came from, so the frozen layer
is traceable to one pin. Never hand-edit the output; re-run this script.

    python scripts/vendor_corpus_sandhi.py [--coverage 90] [--check]

`--check` rebuilds in memory and exits 1 if the vendored file differs (ignoring
source.commit, which tracks kosha's HEAD rather than the TSV).
"""
import argparse
import json
import os
import re
import subprocess
import sys

from _common import kosha_checkout

sys.stdout.reconfigure(encoding='utf-8')
sys.stderr.reconfigure(encoding='utf-8')

KOSHA_REF = 'origin/main'
TSV_IN_KOSHA = 'data/sandhi/corpus_sandhi.tsv'

HERE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(HERE, 'resources', 'data', 'corpus_sandhi', 'corpus_sandhi_top.json')

TOP_TEXT_RE = re.compile(r'^(.*)\((\d+)\)$')
SENTENCE_MAX = 160


def git(*args):
    return subprocess.run(['git', '-C', str(kosha_checkout()), *args], check=True,
                          capture_output=True).stdout


def parse_top_texts(cell):
    out = []
    # Text names can contain ", " inside parentheses ("Mahābhārata (full, incl. …)(23014)"),
    # so split only on ", " that follows a closing count ")".
    for part in re.split(r'(?<=\d\)), ', cell.strip()):
        m = TOP_TEXT_RE.match(part.strip())
        if m:
            out.append({'text': m.group(1).strip(), 'count': int(m.group(2))})
    return out


def first_example(cell):
    seg = cell.split(' · ')[0].strip()
    if not seg:
        return None
    # kosha appends the junction after the sentence: "left+right", where either side
    # may carry "form→surface" and the '+' is sometimes space-detached ("a→b +c").
    words = seg.split(' ')
    for i, w in enumerate(words):
        if '+' in w or '→' in w:
            sentence = ' '.join(words[:i])
            split = ' '.join(words[i:]).replace(' +', '+').replace('+ ', '+')
            break
    else:
        sentence, split = seg, ''
    if len(sentence) > SENTENCE_MAX:
        sentence = sentence[:SENTENCE_MAX].rsplit(' ', 1)[0] + ' …'
    return {'sentence': sentence, 'split': split}


def build(coverage):
    commit = git('rev-parse', KOSHA_REF).decode().strip()
    blob = git('rev-parse', f'{KOSHA_REF}:{TSV_IN_KOSHA}').decode().strip()
    raw = git('show', f'{KOSHA_REF}:{TSV_IN_KOSHA}').decode('utf-8')
    lines = [ln for ln in raw.splitlines() if ln.strip()]
    header = lines[0].split('\t')
    rows = [dict(zip(header, ln.split('\t'))) for ln in lines[1:]]
    total_events = sum(int(r['global_count']) for r in rows)
    rows.sort(key=lambda r: -int(r['global_count']))

    rules, cum, cut = [], 0, {}
    for rank, r in enumerate(rows, 1):
        count = int(r['global_count'])
        cum += count
        cum_pct = round(100 * cum / total_events, 2)
        for mark in (50, 80, 90):
            if mark not in cut and cum_pct >= mark:
                cut[mark] = rank
        rules.append({
            'rank': rank,
            'rule': r['rule'],
            'category': r['category'],
            'count': count,
            'pct': round(100 * count / total_events, 2),
            'cum_pct': cum_pct,
            'n_texts': int(r['n_texts']),
            'top_texts': parse_top_texts(r['top_texts']),
            'example': first_example(r.get('examples', '')),
        })
        if cum_pct >= coverage:
            break

    categories = {}
    for r in rules:
        categories[r['category']] = categories.get(r['category'], 0) + r['count']

    return {
        'schema': 'corpus_sandhi_top_v1',
        'source': {
            'dataset': 'corpus-sandhi',
            'repo': 'https://github.com/gasyoun/kosha',
            'path': TSV_IN_KOSHA,
            'commit': commit,
            'blob': blob,
            'license': 'CC BY-SA 4.0',
            'credit': 'DCS (Oliver Hellwig), CC BY-SA 4.0; rule induction and ranking — kosha, Dr. Mārcis Gasūns',
        },
        'stats': {
            'texts': 41,
            'events': total_events,
            'rules_total': len(rows),
            'rules_baked': len(rules),
            'coverage_cutoff_pct': coverage,
            'rules_for_50_pct': cut.get(50),
            'rules_for_80_pct': cut.get(80),
            'rules_for_90_pct': cut.get(90),
        },
        'categories': [
            {'category': c, 'count': n, 'pct_of_baked': round(100 * n / sum(categories.values()), 1)}
            for c, n in sorted(categories.items(), key=lambda kv: -kv[1])
        ],
        'rules': rules,
    }


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--coverage', type=float, default=90.0)
    ap.add_argument('--check', action='store_true')
    args = ap.parse_args()

    data = build(args.coverage)
    text = json.dumps(data, ensure_ascii=False, indent=1) + '\n'

    if args.check:
        current = open(OUT, encoding='utf-8').read() if os.path.exists(OUT) else ''
        # source.commit is kosha's HEAD at vendoring time and moves with every
        # unrelated kosha commit; source.blob already pins the TSV bytes. Carry the
        # vendored commit into the rebuild so --check flags data drift only.
        if current:
            data['source']['commit'] = json.loads(current)['source']['commit']
            text = json.dumps(data, ensure_ascii=False, indent=1) + '\n'
        if current != text:
            print(f'DRIFT: {OUT} differs from a rebuild off kosha {KOSHA_REF}')
            return 1
        print('OK: vendored corpus sandhi layer matches kosha pin')
        return 0

    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    with open(OUT, 'w', encoding='utf-8', newline='\n') as fh:
        fh.write(text)
    s = data['stats']
    print(f"wrote {OUT}: {s['rules_baked']} rules of {s['rules_total']} "
          f"({s['events']} events); 50%={s['rules_for_50_pct']} 80%={s['rules_for_80_pct']} "
          f"90%={s['rules_for_90_pct']}; kosha {data['source']['commit'][:9]}")
    return 0


if __name__ == '__main__':
    sys.exit(main())
