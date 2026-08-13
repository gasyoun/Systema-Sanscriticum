# Grammar Lab G2 retrieval and access (H2493)

_Created: 13-08-2026 · Last updated: 13-08-2026_

G3 follow-on is documented separately.

Systema import + explorer + offline search over the pinned G1 bundle
([SanskritGrammar v0.121.6](https://github.com/gasyoun/SanskritGrammar/releases/tag/v0.121.6)).

## Commands

```powershell
php artisan grammar-lab:sync --dry-run
php artisan grammar-lab:sync
php artisan grammar-lab:eval-search
php artisan test --filter=GrammarLab
```

## Flags (default OFF)

| Flag | Env | Meaning |
|---|---|---|
| `features.grammar_lab` | `GRAMMAR_LAB` | Explorer + landing. OFF → 404. |
| `features.grammar_lab_semantic` | `GRAMMAR_LAB_SEMANTIC` | Hybrid vector. OFF leaves BM25 live. |

Prod enable is a separate ops step (`GRAMMAR_LAB=true` + `config:cache`). Do not
flip `GRAMMAR_LAB_SEMANTIC` until G1 `semantic_ready=true` and frozen Recall@5 ≥ 0.85.

## Authorization

`GrammarLabAccess::canUse()` is the only product question. Satisfied by admin-like
role, an active `grammar_lab_entitlements` row, or an access-granting payment on a
slug listed in `GRAMMAR_LAB_COURSE_SLUGS` (empty by default — no silent grant).
G4 may add subscription lifecycle; it must reuse this resolver.

## Search

Lexical: exact/prefix aliases in RU/Deva/IAST/SLP1 + Okapi BM25.
Vector: PHP twin of `charngram-hash-v1`. G1 pin is not semantic-ready.

G3 learning loop: [GRAMMAR_LAB_G3_LEARNING_LOOP_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/GRAMMAR_LAB_G3_LEARNING_LOOP_2026.md).

_Dr. Mārcis Gasūns_
