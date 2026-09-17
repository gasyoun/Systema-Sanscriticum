#!/usr/bin/env python3
"""Proof for the H4783 gate: the current baseline passes, mismatched snapshots fail.

Each mismatch is built on a temporary copy of the real vendored snapshots. The
compatibility mismatches also RE-HASH their manifest, the way a real vendor refresh
ships a self-consistent manifest, so a green pin-integrity layer cannot be what
catches them.

    python -m unittest scripts/test_check_vendored_corpus_compat.py -v
"""
import contextlib
import hashlib
import io
import json
import os
import shutil
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import check_vendored_corpus_compat as gate  # noqa: E402

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SNAPSHOT_DIRS = gate.FREEZE_DIRS + [gate.GRAMMAR_LAB_DIR]


def write_json(path, doc):
    with open(path, 'w', encoding='utf-8', newline='\n') as fh:
        json.dump(doc, fh, ensure_ascii=False, indent=2)
        fh.write('\n')


def read_json(path):
    with open(path, encoding='utf-8') as fh:
        return json.load(fh)


class GateTest(unittest.TestCase):
    def setUp(self):
        self.root = tempfile.mkdtemp(prefix='h4783-')
        for name in SNAPSHOT_DIRS:
            shutil.copytree(os.path.join(REPO, 'resources', 'data', name),
                            os.path.join(self.root, 'resources', 'data', name))

    def tearDown(self):
        shutil.rmtree(self.root, ignore_errors=True)

    def path(self, name, filename):
        return os.path.join(self.root, 'resources', 'data', name, filename)

    def run_gate(self):
        with contextlib.redirect_stdout(io.StringIO()):
            return gate.run(self.root)

    def rehash(self, name, filename):
        """Make the snapshot's manifest agree with the edited file, like a vendor refresh."""
        with open(self.path(name, filename), 'rb') as fh:
            payload = fh.read()
        digest = hashlib.sha256(payload).hexdigest()
        if name == gate.GRAMMAR_LAB_DIR:
            manifest_path = self.path(name, 'manifest.json')
            manifest = read_json(manifest_path)
            for feed in manifest['feeds']:
                if os.path.basename(feed['path']) == filename:
                    feed['sha256'] = digest
        else:
            manifest_path = self.path(name, 'MANIFEST.json')
            manifest = read_json(manifest_path)
            for pack in manifest['packs']:
                if os.path.basename(pack['pin_path']) == filename:
                    pack['sha256'] = digest
                    pack['bytes'] = len(payload)
        write_json(manifest_path, manifest)

    def assertFailsWith(self, needle):
        report = self.run_gate()
        self.assertTrue(report.failures, 'mismatched snapshot passed the gate')
        self.assertTrue(any(needle in line for line in report.failures),
                        'expected a failure mentioning %r, got %r' % (needle, report.failures))

    # -- baseline --------------------------------------------------------------

    def test_current_baseline_passes(self):
        report = self.run_gate()
        self.assertEqual([], report.failures)

    def test_real_checkout_passes(self):
        with contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual([], gate.run(REPO).failures)

    # -- taxonomy mismatches (re-hashed, so only compatibility can catch them) --

    def test_dropped_topic_breaks_frozen_evaluation(self):
        bundle_path = self.path('grammar_lab', 'grammar_lab.json')
        bundle = read_json(bundle_path)
        queries = read_json(self.path('grammar_lab', 'frozen_queries.json'))
        target = queries['queries'][0]['acceptable_topic_ids'][0]
        bundle['topics'] = [t for t in bundle['topics'] if t['id'] != target]
        bundle['exercises'] = [e for e in bundle['exercises'] if e['topic_id'] != target]
        write_json(bundle_path, bundle)
        self.rehash('grammar_lab', 'grammar_lab.json')
        self.assertFailsWith('frozen_queries.json')

    def test_renamed_topic_breaks_vectors_and_queries(self):
        bundle_path = self.path('grammar_lab', 'grammar_lab.json')
        bundle = read_json(bundle_path)
        old = bundle['topics'][0]['id']
        bundle['topics'][0]['id'] = old + '-v2'
        for exercise in bundle['exercises']:
            if exercise['topic_id'] == old:
                exercise['topic_id'] = old + '-v2'
        write_json(bundle_path, bundle)
        self.rehash('grammar_lab', 'grammar_lab.json')
        self.assertFailsWith('topic_vectors.json: vector ids do not match topic ids')

    def test_unpublished_topic_breaks_frozen_evaluation(self):
        bundle_path = self.path('grammar_lab', 'grammar_lab.json')
        bundle = read_json(bundle_path)
        bundle['topics'][0]['status'] = 'needs_review'
        write_json(bundle_path, bundle)
        self.rehash('grammar_lab', 'grammar_lab.json')
        self.assertFailsWith('not published by the bundle')

    def test_schema_major_bump_fails(self):
        queries_path = self.path('grammar_lab', 'frozen_queries.json')
        queries = read_json(queries_path)
        queries['schema_version'] = '2.0.0'
        write_json(queries_path, queries)
        self.rehash('grammar_lab', 'frozen_queries.json')
        self.assertFailsWith('off the pinned major')

    def test_vector_dimensionality_change_fails(self):
        vectors_path = self.path('grammar_lab', 'topic_vectors.json')
        vectors = read_json(vectors_path)
        vectors['vectors'][0]['vector'] = vectors['vectors'][0]['vector'][:-1]
        write_json(vectors_path, vectors)
        self.rehash('grammar_lab', 'topic_vectors.json')
        self.assertFailsWith('not of dimensionality')

    # -- corpus mismatches ----------------------------------------------------

    def test_reshaped_token_fails(self):
        pack_path = self.path('nala_subhashita', 'nala-2.json')
        pack = read_json(pack_path)
        token = pack['sentences'][0]['tokens'][0]
        token['headword'] = token.pop('lemma')
        write_json(pack_path, pack)
        self.rehash('nala_subhashita', 'nala-2.json')
        self.assertFailsWith('lacks lemma')

    def test_stats_disagreeing_with_sentences_fails(self):
        pack_path = self.path('cohort_start_chteniya', 'hitopadesa-0.json')
        pack = read_json(pack_path)
        pack['sentences'] = pack['sentences'][:-1]
        write_json(pack_path, pack)
        self.rehash('cohort_start_chteniya', 'hitopadesa-0.json')
        self.assertFailsWith('stats.sentences says')

    def test_broken_subhashita_shape_fails(self):
        pack_path = self.path('nala_subhashita', 'subhashita_beginner_pack.json')
        pack = read_json(pack_path)
        pack['sayings'][0]['lines'] = [{'text': 'flat'}]
        write_json(pack_path, pack)
        self.rehash('nala_subhashita', 'subhashita_beginner_pack.json')
        self.assertFailsWith('lacks lines[].chunks[]')

    def test_malformed_topic_row_is_a_fail_line_not_a_crash(self):
        # Found by the independent verifier: a topic without `id` used to raise KeyError
        # and drop the FAIL lines already gathered (here: the corpus pin mismatch below).
        with open(self.path('nala_subhashita', 'nala-1.json'), 'ab') as fh:
            fh.write(b' ')
        bundle_path = self.path('grammar_lab', 'grammar_lab.json')
        bundle = read_json(bundle_path)
        del bundle['topics'][0]['id']
        write_json(bundle_path, bundle)
        self.rehash('grammar_lab', 'grammar_lab.json')
        report = self.run_gate()
        self.assertTrue(any('malformed snapshot' in line for line in report.failures))
        self.assertTrue(any('nala-1.json: manifest says sha256' in line for line in report.failures))

    # -- pin-integrity mismatches (manifest NOT updated) ----------------------

    def test_hand_edited_corpus_bytes_fail(self):
        with open(self.path('nala_subhashita', 'nala-1.json'), 'ab') as fh:
            fh.write(b' ')
        self.assertFailsWith('nala_subhashita/nala-1.json: manifest says sha256')

    def test_unmanifested_file_fails(self):
        with open(self.path('cohort_start_chteniya', 'extra.json'), 'w', encoding='utf-8') as fh:
            fh.write('{}\n')
        self.assertFailsWith('not listed in the snapshot manifest')

    def test_known_defect_does_not_mask_further_drift(self):
        vectors_path = self.path('grammar_lab', 'topic_vectors.json')
        vectors = read_json(vectors_path)
        vectors['revision'] = 'tampered'
        write_json(vectors_path, vectors)
        self.assertFailsWith('matches neither the manifest nor the recorded known-defect bytes')


if __name__ == '__main__':
    unittest.main()
