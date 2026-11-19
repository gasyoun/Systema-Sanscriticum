# H6320 restore drill transcript
date: 2026-10-09 21:18:16Z | host: samskrtam150
repo: sftp:restic-push@192.168.200.91:/systema | restic: 0.18.0
snapshot: 994958bf (systema lane, 2026-10-09 20:34:30 UTC)

## restic restore (full snapshot -> /tmp)
$ restic restore 994958bf --target /tmp/backrest-drill-H6320/994958bf
restoring snapshot 994958bf of [/var/backups/systema/db /var/www/html/storage/app /var/www/html/.env /etc/nginx] at 2026-10-09 20:34:30.418765917 +0000 UTC by root@samskrtam150 to /tmp/backrest-drill-H6320/994958bf
Summary: Restored 16144 files/dirs (5.602 GiB) in 0:15
Remove(<lock/1960df9b4e>) failed: Remove /systema/locks/1960df9b4e0223618a6241912ec69c753202d203c9cd3e8828aa3fda2a0574f1: permission denied
RESTORE_OK rc=0 elapsed=0s

## file listing
files: 14663 | size: 5.7G
top-level restored entries:
etc
var

## sampled files (largest, mid, small) — sha256 restored vs restic dump
Remove(<lock/3b512866cf>) failed: Remove /systema/locks/3b512866cf4e1a4292fed83417772628a3bae27b8af79a42a9a987b015b75568: permission denied
error while unlocking: Remove /systema/locks/3b512866cf4e1a4292fed83417772628a3bae27b8af79a42a9a987b015b75568: permission deniedMATCH  var/backups/systema/db/laravel.sql
    restored sha256: f4119ad33554b8d425c542993eaba4593e55a1221de5192d4d52bfc596648b95
    restic-dump sha256: f4119ad33554b8d425c542993eaba4593e55a1221de5192d4d52bfc596648b95
Load(<lock/c8c88875f8>, 0, 0) failed: Open /systema/locks/c8c88875f852e3ea851ca997a09b4d344fd7cbeff434ad740b0d9bf34c74e312: permission denied
Load(<lock/c8c88875f8>, 0, 0) failed: Open /systema/locks/c8c88875f852e3ea851ca997a09b4d344fd7cbeff434ad740b0d9bf34c74e312: permission denied
Remove(<lock/be843f05f6>) failed: Remove /systema/locks/be843f05f6b4b25b1cfdc3ae400ee42e8f886bc9c4c25b553102ec6bd8db4f29: permission denied
error while unlocking: Remove /systema/locks/be843f05f6b4b25b1cfdc3ae400ee42e8f886bc9c4c25b553102ec6bd8db4f29: permission deniedMATCH  var/www/html/storage/app/telegram-harvest/raw/archive/384124504/2025-02-28.jsonl
    restored sha256: 1a60a5f8fbe7b2c9ae7c3ef38e3a6f74f0ff30320121c78a2b4eb16fc449cff4
    restic-dump sha256: 1a60a5f8fbe7b2c9ae7c3ef38e3a6f74f0ff30320121c78a2b4eb16fc449cff4
Remove(<lock/4327fb1b10>) failed: Remove /systema/locks/4327fb1b107ca77678b2864d7c00a28667fc5998d122aae6206b08c8144bae49: permission denied
error while unlocking: Remove /systema/locks/4327fb1b107ca77678b2864d7c00a28667fc5998d122aae6206b08c8144bae49: permission deniedMATCH  var/www/html/storage/app/telegram-support/marcisgasuns/session.madeline/ipcState.php.lock
    restored sha256: e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855
    restic-dump sha256: e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855

## live-source cross-check (files that still exist on this host)
live-match: YES  /var/backups/systema/db/laravel.sql
live-match: YES  /var/www/html/storage/app/telegram-harvest/raw/archive/384124504/2025-02-28.jsonl
live-match: YES  /var/www/html/storage/app/telegram-support/marcisgasuns/session.madeline/ipcState.php.lock

## verdict: PASS=3 FAIL=0
DRILL_VERDICT: PASS
drill end: 2026-10-09 21:20:11Z
drill finished, rc=0

## supplementary sampling: 3 non-empty files (2026-10-09, post-drill)
Remove(<lock/1a8d39d403>) failed: Remove /systema/locks/1a8d39d4035101971e525976bb851b86fdb736f100df3036829c0eb0aeb3b990: permission denied
error while unlocking: Remove /systema/locks/1a8d39d4035101971e525976bb851b86fdb736f100df3036829c0eb0aeb3b990: permission deniedMATCH  var/www/html/storage/app/public/lesson_materials/45/Кашмирский шиваизм (часть первая), 14. Максим Ворошилов 2025.pdf  (69918782 bytes)
    restored sha256: c0b8c1e34858a3cef763f17e24ed50447905cae8e47adc2924fb0ac07cb4673d
    restic-dump sha256: c0b8c1e34858a3cef763f17e24ed50447905cae8e47adc2924fb0ac07cb4673d
Remove(<lock/6cdb438dea>) failed: Remove /systema/locks/6cdb438deab472abeb45dbddefe04205a6013d0a67945936480d2467b4bd3747: permission denied
error while unlocking: Remove /systema/locks/6cdb438deab472abeb45dbddefe04205a6013d0a67945936480d2467b4bd3747: permission deniedMATCH  var/www/html/storage/app/telegram-harvest/raw/archive/893257701/2025-09-17.jsonl  (4770 bytes)
    restored sha256: 33441bdbdc43814de733e87ea014f743ff729d7f89182f4366ec744a30159ddb
    restic-dump sha256: 33441bdbdc43814de733e87ea014f743ff729d7f89182f4366ec744a30159ddb
Remove(<lock/8758b4b972>) failed: Remove /systema/locks/8758b4b97237fc6abb45f73965a8d946208c5ca4dbb4591ca77e3cc749bbec99: permission denied
error while unlocking: Remove /systema/locks/8758b4b97237fc6abb45f73965a8d946208c5ca4dbb4591ca77e3cc749bbec99: permission deniedMATCH  var/www/html/storage/app/telegram-harvest/raw/archive/350942251/2026-06-01.jsonl  (1026 bytes)
    restored sha256: a65c1c7c8937e5f37ea1bd999844adf76bf3cae67494b8f0281b0b6f1e61cf7b
    restic-dump sha256: a65c1c7c8937e5f37ea1bd999844adf76bf3cae67494b8f0281b0b6f1e61cf7b
## supplementary verdict: PASS=3 FAIL=0 (all samples non-empty)
SUPPLEMENT_VERDICT: PASS
resample done
