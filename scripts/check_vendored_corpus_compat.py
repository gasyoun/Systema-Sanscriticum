#!/usr/bin/env python3
"""Pre-merge compatibility gate for vendored corpus and taxonomy snapshots (H4783).

The vendored snapshots under `resources/data/` are refreshed from upstream by
`scripts/vendor_cohort_start_chteniya_packs.py`, `scripts/vendor_nala_subhashita_packs.py`
and `php artisan grammar-lab:sync`. Those tools verify bytes against the upstream
manifest, but only on the machine that has the upstream clone, and only when someone
runs them. Nothing on the PR path checked that a refreshed snapshot still fits what
depends on it: the frozen Grammar Lab evaluation queries, the exercises and vectors
keyed by topic id, and the reading-pack fields the cabinet reads. A snapshot that
drops or renames a topic, or reshapes a token, merged green and failed at runtime or
in an 11-minute PHPUnit run. This gate runs in seconds, needs only the standard
library, and needs no upstream clone.

Two layers:

1. Pin integrity. Every file in a snapshot directory is listed in that directory's
   own vendored manifest, and its sha256 and byte count match the manifest. A file the
   manifest does not list fails, so a hand-dropped extra file cannot hide in a pack dir.
2. Compatibility. The Grammar Lab taxonomy (topic ids in `grammar_lab.json`) must cover
   every id its dependants name: frozen evaluation queries, exercises, prerequisites and
   topic vectors. Schema versions must stay on the major the importer is pinned to.
   Reading packs must keep the fields `ReadingPackController` / `CourseReadingPacks`
   read, and `stats.sentences` must equal the number of sentences shipped.

    python scripts/check_vendored_corpus_compat.py [--root PATH]

Exit 0 = compatible, 1 = at least one FAIL line. `--root` points at a repository copy;
the unit test uses it to prove that deliberately mismatched snapshots fail.
"""
import argparse
import hashlib
import json
import os
import sys

sys.stdout.reconfigure(encoding='utf-8')
sys.stderr.reconfigure(encoding='utf-8')

HERE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# Files that describe a snapshot rather than belong to it.
PROVENANCE_FILES = {'MANIFEST.json', 'manifest.json', 'PIN.md'}

# Freeze snapshots vendored from kosha: MANIFEST.json packs[] carry pin_path + sha256 + bytes.
FREEZE_DIRS = ['cohort_start_chteniya', 'nala_subhashita']

# Grammar Lab snapshot vendored from SanskritGrammar: manifest.json feeds[] carry path + sha256.
GRAMMAR_LAB_DIR = 'grammar_lab'

# Must match config/grammar_lab.php pin.schema_version / pin.bundle_version majors:
# GrammarLabImporter::assertPin() refuses another bundle major at import time.
GRAMMAR_LAB_SCHEMA_MAJOR = 1

# A manifest defect that already shipped upstream, pinned to the exact bytes seen. The
# SanskritGrammar v0.121.6 manifest lists topic_vectors sha256 e5150fab…, but the file
# that tag actually ships (and that was vendored here, byte-identical) hashes to
# e210de53…. Recorded instead of waved through: any other change to topic_vectors.json
# still fails, and once upstream fixes its manifest this entry goes stale and the gate
# says so.
KNOWN_MANIFEST_DEFECTS = {
    ('grammar_lab', 'topic_vectors.json'): {
        'manifest_sha256': 'e5150fab87e0848bdd133be4d298fac27693c8c8fdc2317733b287103e45ff0f',
        'actual_sha256': 'e210de535fc0c8d3b3d49b467750080c8e4fa772e409cc8ff2810f9f7a31b54b',
        'why': 'SanskritGrammar v0.121.6 export/manifest.json carries a stale topic_vectors hash',
    },
}

# Fields the reader page reads WITHOUT a `??` fallback (resources/views/reading/partials/
# pack.blade.php, ReadingPackController::readPack). Dropping or renaming one is an
# "Undefined array key" 500 for every student on that pack. Fallback-read fields
# (gloss, gloss_ru, slp1, morph, n) are legitimately absent on some tokens and are
# not part of the contract.
READING_PACK_TOP = ('title', 'ref', 'text_name', 'source', 'stats', 'sentences')
READING_PACK_STATS = ('sentences', 'tokens', 'linked_tokens', 'link_rate_pct')
READING_PACK_SENTENCE = ('locus', 'text', 'tokens')
READING_PACK_TOKEN = ('form', 'lemma')
# SubhashitaReadingPackAdapter reshapes sayings[]/lines[].chunks[] at read time.
SUBHASHITA_TOP = ('slug', 'title', 'sayings')


class Report:
    def __init__(self):
        self.failures = []
        self.notes = []

    def fail(self, msg):
        self.failures.append(msg)

    def note(self, msg):
        self.notes.append(msg)


def sha256_of(path):
    with open(path, 'rb') as fh:
        payload = fh.read()
    return hashlib.sha256(payload).hexdigest(), len(payload)


def load_json(path, report, label):
    try:
        with open(path, encoding='utf-8') as fh:
            return json.load(fh)
    except (OSError, ValueError) as exc:
        report.fail('%s: cannot read JSON (%s)' % (label, exc))
        return None


def major(version):
    try:
        return int(str(version).split('.')[0])
    except ValueError:
        return None


def check_directory_listing(data_dir, name, listed, report):
    """Every non-provenance file in the directory must be a manifest-listed file."""
    for entry in sorted(os.listdir(os.path.join(data_dir, name))):
        if entry in PROVENANCE_FILES:
            continue
        if entry not in listed:
            report.fail('%s/%s: file is not listed in the snapshot manifest '
                        '(vendor it through the manifest, not by hand)' % (name, entry))


def check_pinned_file(data_dir, name, filename, want_sha, want_bytes, report):
    path = os.path.join(data_dir, name, filename)
    have_sha, have_bytes = sha256_of(path)
    known = KNOWN_MANIFEST_DEFECTS.get((name, filename))
    if known and want_sha == known['manifest_sha256']:
        if have_sha == known['actual_sha256']:
            report.note('%s/%s: known upstream manifest defect accepted (%s)'
                        % (name, filename, known['why']))
            return
        report.fail('%s/%s: sha256 %s matches neither the manifest nor the recorded '
                    'known-defect bytes' % (name, filename, have_sha[:12]))
        return
    if known and have_sha == want_sha:
        report.note('%s/%s: manifest hash now matches the bytes; the KNOWN_MANIFEST_DEFECTS '
                    'entry is stale and can be removed' % (name, filename))
    if have_sha != want_sha:
        report.fail('%s/%s: manifest says sha256 %s, vendored file is %s'
                    % (name, filename, want_sha[:12], have_sha[:12]))
    if want_bytes is not None and have_bytes != want_bytes:
        report.fail('%s/%s: manifest says %d bytes, vendored file is %d bytes'
                    % (name, filename, want_bytes, have_bytes))


def check_reading_pack(label, pack, report):
    missing = [k for k in READING_PACK_TOP if k not in pack]
    if missing:
        report.fail('%s: reading pack lacks top-level field(s) %s' % (label, ', '.join(missing)))
        return
    sentences = pack['sentences']
    if not isinstance(sentences, list) or not sentences:
        report.fail('%s: sentences[] is empty or not a list' % label)
        return
    stats = pack['stats'] if isinstance(pack['stats'], dict) else {}
    lack = [k for k in READING_PACK_STATS if k not in stats]
    if lack:
        report.fail('%s: stats lacks %s' % (label, ', '.join(lack)))
        return
    if stats['sentences'] != len(sentences):
        report.fail('%s: stats.sentences says %s, pack ships %d sentences'
                    % (label, stats['sentences'], len(sentences)))
    shipped_tokens = sum(len(s.get('tokens') or []) for s in sentences)
    if stats['tokens'] != shipped_tokens:
        report.fail('%s: stats.tokens says %s, pack ships %d tokens'
                    % (label, stats['tokens'], shipped_tokens))
    for si, sentence in enumerate(sentences):
        lack = [k for k in READING_PACK_SENTENCE if k not in sentence]
        if lack:
            report.fail('%s: sentence %d lacks %s' % (label, si, ', '.join(lack)))
            return
        for ti, token in enumerate(sentence['tokens']):
            lack = [k for k in READING_PACK_TOKEN if k not in token]
            if lack:
                report.fail('%s: sentence %d token %d lacks %s'
                            % (label, si, ti, ', '.join(lack)))
                return


def check_subhashita_pack(label, pack, report):
    missing = [k for k in SUBHASHITA_TOP if k not in pack]
    if missing:
        report.fail('%s: subhashita pack lacks top-level field(s) %s' % (label, ', '.join(missing)))
        return
    for si, saying in enumerate(pack['sayings']):
        lines = saying.get('lines')
        if not isinstance(lines, list) or any(not isinstance(l.get('chunks'), list) for l in lines):
            report.fail('%s: saying %d lacks lines[].chunks[]' % (label, si))
            return


def check_freeze_dir(data_dir, name, report):
    manifest = load_json(os.path.join(data_dir, name, 'MANIFEST.json'), report, name + '/MANIFEST.json')
    if manifest is None:
        return 0
    listed = {}
    for pack in manifest.get('packs', []):
        listed[os.path.basename(pack['pin_path'])] = pack
    check_directory_listing(data_dir, name, listed, report)
    vendored = 0
    for filename, pack in sorted(listed.items()):
        path = os.path.join(data_dir, name, filename)
        if not os.path.isfile(path):
            continue  # the freeze is wider than what this repo renders; subsets are fine
        vendored += 1
        check_pinned_file(data_dir, name, filename, pack['sha256'], pack.get('bytes'), report)
        label = '%s/%s' % (name, filename)
        if pack.get('kind') == 'reading_pack':
            body = load_json(path, report, label)
            if body is not None:
                check_reading_pack(label, body, report)
        elif pack.get('kind') == 'subhashita_reader_pack':
            body = load_json(path, report, label)
            if body is not None:
                check_subhashita_pack(label, body, report)
    if vendored == 0:
        report.fail('%s: no manifest-listed file is vendored' % name)
    return vendored


def check_grammar_lab(data_dir, report):
    name = GRAMMAR_LAB_DIR
    base = os.path.join(data_dir, name)
    manifest = load_json(os.path.join(base, 'manifest.json'), report, name + '/manifest.json')
    if manifest is None:
        return
    listed = {os.path.basename(feed['path']): feed for feed in manifest.get('feeds', [])}
    check_directory_listing(data_dir, name, listed, report)
    for filename, feed in sorted(listed.items()):
        if os.path.isfile(os.path.join(base, filename)):
            check_pinned_file(data_dir, name, filename, feed['sha256'], None, report)

    bundle = load_json(os.path.join(base, 'grammar_lab.json'), report, name + '/grammar_lab.json')
    queries = load_json(os.path.join(base, 'frozen_queries.json'), report, name + '/frozen_queries.json')
    vectors = load_json(os.path.join(base, 'topic_vectors.json'), report, name + '/topic_vectors.json')
    if bundle is None or queries is None or vectors is None:
        return

    for label, doc in (('manifest.json', manifest), ('grammar_lab.json', bundle),
                       ('frozen_queries.json', queries), ('topic_vectors.json', vectors)):
        if major(doc.get('schema_version')) != GRAMMAR_LAB_SCHEMA_MAJOR:
            report.fail('%s/%s: schema_version %s is off the pinned major %d'
                        % (name, label, doc.get('schema_version'), GRAMMAR_LAB_SCHEMA_MAJOR))
    if major(bundle.get('bundle_version')) != GRAMMAR_LAB_SCHEMA_MAJOR:
        report.fail('%s/grammar_lab.json: bundle_version %s is off the pinned major %d'
                    % (name, bundle.get('bundle_version'), GRAMMAR_LAB_SCHEMA_MAJOR))

    topics = bundle.get('topics', [])
    all_ids = [t['id'] for t in topics]
    if len(set(all_ids)) != len(all_ids):
        report.fail('%s/grammar_lab.json: duplicate topic ids' % name)
    ids = set(all_ids)
    published = {t['id'] for t in topics if t.get('status') == 'published'}
    if manifest.get('published_topic_count') != len(published):
        report.fail('%s: manifest published_topic_count=%s, bundle has %d published topics'
                    % (name, manifest.get('published_topic_count'), len(published)))

    # The frozen evaluation only scores against topics the importer publishes.
    dangling = sorted({tid for q in queries.get('queries', [])
                       for tid in q.get('acceptable_topic_ids', []) if tid not in published})
    if dangling:
        report.fail('%s/frozen_queries.json: %d acceptable topic id(s) not published by the '
                    'bundle, e.g. %s' % (name, len(dangling), ', '.join(dangling[:3])))
    unanswerable = [q['id'] for q in queries.get('queries', []) if not q.get('acceptable_topic_ids')]
    if unanswerable:
        report.fail('%s/frozen_queries.json: %d query(ies) with no acceptable topic, e.g. %s'
                    % (name, len(unanswerable), ', '.join(unanswerable[:3])))

    orphan_ex = sorted({e['topic_id'] for e in bundle.get('exercises', []) if e['topic_id'] not in ids})
    if orphan_ex:
        report.fail('%s/grammar_lab.json: exercise(s) keyed to unknown topic id(s) %s'
                    % (name, ', '.join(orphan_ex[:3])))
    orphan_pre = sorted({p for t in topics for p in t.get('prerequisites', []) if p not in ids})
    if orphan_pre:
        report.fail('%s/grammar_lab.json: prerequisite(s) naming unknown topic id(s) %s'
                    % (name, ', '.join(orphan_pre[:3])))

    vector_ids = [v['id'] for v in vectors.get('vectors', [])]
    if set(vector_ids) != ids or len(vector_ids) != len(ids):
        only_v = sorted(set(vector_ids) - ids)
        only_t = sorted(ids - set(vector_ids))
        report.fail('%s/topic_vectors.json: vector ids do not match topic ids '
                    '(vectors-only %s; topics-only %s)' % (name, only_v[:3], only_t[:3]))
    dim = vectors.get('dimensionality')
    bad_dim = [v['id'] for v in vectors.get('vectors', []) if len(v.get('vector', [])) != dim]
    if bad_dim:
        report.fail('%s/topic_vectors.json: %d vector(s) not of dimensionality %s, e.g. %s'
                    % (name, len(bad_dim), dim, bad_dim[0]))


def run(root):
    data_dir = os.path.join(root, 'resources', 'data')
    report = Report()
    for name in FREEZE_DIRS:
        count = check_freeze_dir(data_dir, name, report)
        print('checked %-22s %d pinned file(s)' % (name, count))
    check_grammar_lab(data_dir, report)
    print('checked %-22s taxonomy vs frozen queries, exercises, vectors' % GRAMMAR_LAB_DIR)
    return report


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--root', default=HERE, help='repository root to check (default: this checkout)')
    args = ap.parse_args(argv)
    report = run(args.root)
    for line in report.notes:
        print('note  %s' % line)
    if report.failures:
        for line in report.failures:
            print('FAIL  %s' % line, file=sys.stderr)
        print('\nvendored corpus/taxonomy snapshots are NOT compatible (%d failure(s))'
              % len(report.failures), file=sys.stderr)
        return 1
    print('\nvendored corpus/taxonomy snapshots are compatible')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
