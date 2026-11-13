# LYW lesson packs — tracked content (H7069)

Canonical, rebuild-safe copy of the nine `zan1` Learn Your Way packs served by
`samskrte.ru` (`.92`). Before H7069 they were hand-copied into
`storage/app/lesson-packs/` on the box, which is outside git: a storage restore,
a rebuild or a second host silently emptied the feature while
`LYW_ENABLED=true` still said it was on.

## Why here

`config/lyw.php` (`lyw.packs_path`) now defaults to:

```php
base_path('resources/data/lesson_packs')
```

so `git pull` / `deploy.sh` is the whole delivery path — no rsync, no manual
copy, no network access needed from the production box. `LYW_PACKS_PATH` still
overrides the root for tests and scratch trees.

The fail-loud half is `php artisan lyw:verify-packs`, wired into `deploy.sh`
and `php artisan guards:verify`: when `lyw.enabled=true` and an enabled group's
`manifest.json` is missing (or the pack is incomplete), the deploy fails
instead of serving 404s.

## Provenance

| Field | Value |
|---|---|
| Upstream repo | `gasyoun/SanskritGrammar` |
| Upstream path | `KocherginaUchebnik_1998/LessonPacks/zan1/` |
| Pinned commit | `24fd1b918efc976513262750d62606e540801db8` |
| Files | 36 (9 packs × manifest + text + mindmap + quizzes) |
| Content bytes | 159,588 |
| Licence | MIT (see `SanskritGrammar/LICENSE`, Copyright (c) 2026 Mārcis Gasūns) |

The pack text is generated from the Kochergina 1998 lesson material by the
build-time generator in `SanskritGrammar/scripts/build_lessonpack.py`; the
`source.claims_yml_sha256` field inside each manifest pins the claims revision.
The upstream repository is MIT-licensed, so the pack bytes are redistributed
here under the same MIT terms.

Machine-readable pin and per-manifest hashes: [`SOURCE.json`](SOURCE.json).

## Vote state is not implied by presence

All nine packs live here on purpose. The 14-10-2026 vote approved five groups
(`base`, `nol-yoga`, `nol-ayurveda`, `nol-kino`, `nol-palomnichestvo`) and
rejected four (`prodolzhayushchiy-*`); serving is decided only by
`config('lyw.enabled_groups')`. Do not delete a rejected pack from this tree —
its presence is what makes the 404 a vote, not a missing file.

## Regenerating

Do not edit pack files here by hand. Regenerate upstream, commit in
`SanskritGrammar`, then copy the whole `zan1` tree and bump the commit in
`SOURCE.json` + this README in the same change.
