#!/usr/bin/env python3
"""H5399 — census the residual un-glossed «Старт чтения» SRS lemmas.

After the kosha-side H5399 fix (subhāṣita gloss index alignment) and the
re-vendor, `lemmas_for_srs.tsv` still has rows with an empty `gloss_ru` plus the
34 Hitopadeśa rows whose Russian field carries English. This script measures,
per candidate source we own, how many of those residual lemmas could be closed
mechanically — so the "needs a licensed lookup + a human sheet" claim is a
measured number, not an assumption.

Sources probed:
  1. resources/data/sa_ru_glossary.json            (DCS lemma rollup, freq floor 2)
  2. the subhāṣita-beginner pack's `surface` gloss slot (inflected RU) — the
     lemma slot is what the freeze prefers; a surface gloss still carries the
     meaning and pymorphy3 can put it in dictionary form (mechanical, grill Q2)
  3. the Hitopadeśa-0 reading pack's token gloss_ru (surface/lemma/root)
  4. database/seeders/data/*/level_*.csv — the Memrise/Anki RU vocab decks

    python scripts/h5399_residual_gloss_census.py
"""
import csv
import json
import os
import re
import sys
import unicodedata
from collections import Counter

sys.stdout.reconfigure(encoding='utf-8')
sys.stderr.reconfigure(encoding='utf-8')

HERE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LEMMAS = os.path.join(HERE, 'resources', 'data', 'cohort_start_chteniya', 'lemmas_for_srs.tsv')
GLOSSARY = os.path.join(HERE, 'resources', 'data', 'sa_ru_glossary.json')
SUBHA = os.path.join(HERE, 'resources', 'data', 'nala_subhashita', 'subhashita_beginner_pack.json')
HITOP = os.path.join(HERE, 'resources', 'data', 'cohort_start_chteniya', 'hitopadesa-0.json')
SEEDERS = os.path.join(HERE, 'database', 'seeders', 'data')

CYR = re.compile(r'[А-Яа-яЁё]')
LATIN = re.compile(r'[A-Za-z]')


def norm(s):
    return unicodedata.normalize('NFC', (s or '').strip().lower())


def is_russian(s):
    return bool(CYR.search(s or '')) and not LATIN.search(s or '')


def load_rows():
    with open(LEMMAS, encoding='utf-8') as fh:
        return list(csv.DictReader(fh, delimiter='\t'))


def residual(rows):
    """Rows the lint would call `empty` or `not_russian`."""
    out = []
    for r in rows:
        g = (r.get('gloss_ru') or '').strip()
        if not g:
            out.append((r, 'empty'))
        elif not is_russian(g):
            out.append((r, 'not_russian'))
    return out


def glossary_index():
    with open(GLOSSARY, encoding='utf-8') as fh:
        d = json.load(fh)
    idx = {}
    for key, e in d['entries'].items():
        for k in (key, e.get('iast'), e.get('slp1')):
            if k:
                idx.setdefault(norm(k), e)
    return idx


def subhashita_surface_index():
    """lemma_slp1 -> [surface glosses] from the beginner pack, index-aligned."""
    if not os.path.isfile(SUBHA):
        return {}
    with open(SUBHA, encoding='utf-8') as fh:
        pack = json.load(fh)
    idx = {}
    for saying in pack.get('sayings', []):
        for line in saying.get('lines', []):
            for chunk in line.get('chunks', []):
                lemmas = chunk.get('lemma_slp1') or []
                glosses = chunk.get('gloss_ru') or []
                if isinstance(lemmas, str):
                    lemmas = [lemmas]
                if isinstance(glosses, dict):
                    glosses = [glosses]
                for i, lem in enumerate(lemmas):
                    g = glosses[i] if i < len(glosses) else None
                    if not isinstance(g, dict):
                        continue
                    for slot in ('lemma', 'surface'):
                        val = (g.get(slot) or '').strip()
                        if val and is_russian(val):
                            idx.setdefault(norm(lem), {}).setdefault(slot, []).append(val)
    return idx


def hitopadesa_index():
    if not os.path.isfile(HITOP):
        return {}
    with open(HITOP, encoding='utf-8') as fh:
        pack = json.load(fh)
    idx = {}
    for sent in pack.get('sentences', []):
        for tok in sent.get('tokens', []):
            gr = tok.get('gloss_ru') or {}
            if not isinstance(gr, dict):
                continue
            keys = {norm(tok.get('lemma')), norm(tok.get('slp1'))}
            for k in filter(None, keys):
                for slot in ('lemma', 'surface', 'root'):
                    val = (gr.get(slot) or '').strip()
                    if val and is_russian(val):
                        idx.setdefault(k, {}).setdefault(slot, []).append(val)
    return idx


def seeder_index():
    idx = {}
    if not os.path.isdir(SEEDERS):
        return idx
    for deck in sorted(os.listdir(SEEDERS)):
        d = os.path.join(SEEDERS, deck)
        if not os.path.isdir(d):
            continue
        for name in sorted(os.listdir(d)):
            if not name.endswith('.csv'):
                continue
            with open(os.path.join(d, name), encoding='utf-8') as fh:
                for row in csv.DictReader(fh):
                    vals = list(row.values())
                    if len(vals) < 2:
                        continue
                    sa, ru = vals[0], vals[1]
                    if sa and ru and is_russian(ru):
                        idx.setdefault(norm(sa), []).append(ru)
    return idx


def main():
    rows = load_rows()
    res = residual(rows)
    print('lemmas_for_srs.tsv rows: %d' % len(rows))
    print('residual: %s' % dict(Counter(s for _, s in res)))
    print('residual by pack: %s' % dict(Counter(r['pack'] for r, _ in res)))

    gl = glossary_index()
    sub = subhashita_surface_index()
    hit = hitopadesa_index()
    seed = seeder_index()
    print('\nsource sizes: glossary=%d subhashita=%d hitopadesa=%d seeders=%d'
          % (len(gl), len(sub), len(hit), len(seed)))

    hits = Counter()
    per_row = []
    for r, status in res:
        key = norm(r['lemma_slp1'])
        found = {}
        if key in gl:
            found['glossary'] = gl[key]['g'][0]
        s = sub.get(key) or {}
        if s.get('lemma'):
            found['subhashita_lemma'] = s['lemma'][0]
        elif s.get('surface'):
            found['subhashita_surface'] = s['surface'][0]
        h = hit.get(key) or {}
        for slot in ('lemma', 'surface', 'root'):
            if h.get(slot):
                found['hitopadesa_' + slot] = h[slot][0]
                break
        if key in seed:
            found['seeders'] = seed[key][0]
        for k in found:
            hits[k] += 1
        if found:
            hits['ANY'] += 1
        per_row.append((r['pack'], r['lemma_slp1'], status, found))

    print('\ncoverage of the %d residual rows:' % len(res))
    for k, v in hits.most_common():
        print('  %-22s %d' % (k, v))

    print('\nfirst 15 covered examples:')
    shown = 0
    for pack, lemma, status, found in per_row:
        if found and shown < 15:
            print('  %-20s %-22s %-12s %s' % (pack, lemma, status, found))
            shown += 1
    print('\nfirst 10 uncovered:')
    shown = 0
    for pack, lemma, status, found in per_row:
        if not found and shown < 10:
            print('  %-20s %-22s %s' % (pack, lemma, status))
            shown += 1

    write_residual_tsv(res, gl, sub, hit, seed)
    return 0


RESIDUAL_TSV = os.path.join(HERE, 'resources', 'data', 'nkrya_lint',
                            'lemmas_gloss_residual.tsv')


def write_residual_tsv(res, gl, sub, hit, seed):
    """The human-gated queue: one row per residual lemma, with its EN gloss.

    This is what a review sheet is cut from — the EN gloss is carried so a human
    (or a drafting pass whose output a human approves) can see the meaning
    without opening the pack. `candidate` is filled only where a source WE OWN
    has a Russian gloss; an empty `candidate` means the row needs a licensed
    lookup, which is the measured dead end this file records.
    """
    fields = ['pack', 'lemma_slp1', 'surface', 'locus', 'status',
              'candidate', 'candidate_source', 'gloss_en']
    out = []
    for r, status in res:
        key = norm(r['lemma_slp1'])
        cand, src = '', ''
        if key in gl:
            cand, src = gl[key]['g'][0], 'sa_ru_glossary'
        elif (sub.get(key) or {}).get('lemma'):
            cand, src = sub[key]['lemma'][0], 'subhashita_pack_lemma'
        elif (hit.get(key) or {}).get('lemma'):
            cand, src = hit[key]['lemma'][0], 'hitopadesa_pack_lemma'
        elif key in seed:
            cand, src = seed[key][0], 'seeder_deck'
        out.append({'pack': r['pack'], 'lemma_slp1': r['lemma_slp1'],
                    'surface': r.get('surface', ''), 'locus': r.get('locus', ''),
                    'status': status, 'candidate': cand, 'candidate_source': src,
                    'gloss_en': (r.get('gloss_en') or '').strip()})
    out.sort(key=lambda d: (d['pack'], d['lemma_slp1']))
    os.makedirs(os.path.dirname(RESIDUAL_TSV), exist_ok=True)
    with open(RESIDUAL_TSV, 'w', encoding='utf-8', newline='') as fh:
        w = csv.DictWriter(fh, fields, delimiter='\t', lineterminator='\n')
        w.writeheader()
        w.writerows(out)
    print('\nwrote %s (%d rows, %d with an in-data candidate)'
          % (os.path.relpath(RESIDUAL_TSV, HERE).replace('\\', '/'),
             len(out), sum(1 for d in out if d['candidate'])))


if __name__ == '__main__':
    raise SystemExit(main())
