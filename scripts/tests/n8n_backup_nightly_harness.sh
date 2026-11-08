#!/usr/bin/env bash
# H7832 — failure-injection harness for scripts/n8n_backup_nightly.sh
#
# Runs the REAL nightly-backup script in a sandbox (paths rewritten via sed,
# sqlite3/flock/ssh/scp shimmed onto PATH) and proves the H7832 hardening:
#
#   Scenario A — good run: verified archive + sidecar land, retention keeps
#               KEEP good archives (legacy sidecar-less files still counted),
#               off-box copy proven by sha256 before the remote prune runs.
#   Scenario B — kill tar mid-write (simulated ENOSPC): script dies, the trap
#               removes the .part staging file, the retention set is untouched —
#               zero partial archives, all KEEP good archives survive.
#   Scenario C — legacy partial archive carrying the FINAL name (leftover of the
#               pre-H7832 script): retention refuses to count it, removes it,
#               and never evicts a good archive in its place. Orphan sidecars
#               and stale .part files are cleaned.
#
# Exit status 0 = all scenarios PASS. Designed for git-bash / POSIX sh.
set -u

HERE="$(cd "$(dirname "$0")" && pwd)"
SCRIPT="$HERE/../n8n_backup_nightly.sh"
KEEP_T=4
FAILURES=0
PASS=0

say()  { printf '%s\n' "$*"; }
pass() { printf 'PASS  %s\n' "$*"; PASS=$((PASS+1)); }
fail() { printf 'FAIL  %s\n' "$*"; FAILURES=$((FAILURES+1)); }
assert_eq() { # desc expected actual
  if [ "$2" = "$3" ]; then pass "$1"; else fail "$1 (expected=[$2] actual=[$3])"; fi
}
assert_files_exist() { # desc file...
  local d="$1"; shift; local f ok=1
  for f in "$@"; do [ -e "$d/$f" ] || { ok=0; fail "$d/$f missing"; }; done
  [ "$ok" = 1 ] && pass "$d: all present"
}

mk_shims() { # $1 shimdir  $2 with_kill_tar(0/1)
  local d="$1"
  mkdir -p "$d"

  cat > "$d/flock" <<'EOF'
#!/usr/bin/env bash
exit 0
EOF

  cat > "$d/sqlite3" <<'EOF'
#!/usr/bin/env bash
# sqlite3 DB ".backup 'TARGET'"   -> copy the db
# sqlite3 DB 'PRAGMA quick_check' -> ok
db="$1"; cmd="${2:-}"
case "$cmd" in
  .backup*)
    tgt=$(printf '%s' "$cmd" | sed -e "s/^\.backup '//" -e "s/'\$//")
    cp -p -- "$db" "$tgt" || exit 1
    exit 0;;
  *) echo ok; exit 0;;
esac
EOF

  cat > "$d/ssh" <<'EOF'
#!/usr/bin/env bash
# last arg is the remote command; the harness already rewrote the remote
# prefix inside the script copy, so plain eval runs it against the sandbox.
cmd="${@: -1}"
exec bash -c "$cmd"
EOF

  cat > "$d/scp" <<'EOF'
#!/usr/bin/env bash
src=""; dest=""
for a in "$@"; do
  case "$a" in
    -*) ;;
    *:*) dest="${a#*:}";;
    *) [ -e "$a" ] && src="$a";;
  esac
done
[ -n "$src" ] && [ -n "$dest" ] || { echo "scp shim: cannot parse args: $*" >&2; exit 255; }
cp -p -- "$src" "$dest/$(basename "$src")" || exit 255
exit 0
EOF

  if [ "$2" = 1 ]; then
    cat > "$d/tar" <<'EOF'
#!/usr/bin/env bash
# H7832 injection: behave like tar killed mid-write (ENOSPC) — leave a few
# hundred KB in the -f target, then die by SIGKILL.
target=""; prev=""
for a in "$@"; do
  case "$prev" in -czhf|-f) target="$a";; esac
  prev="$a"
done
[ -n "$target" ] || { echo "kill-tar shim: no -f target in: $*" >&2; exit 2; }
head -c 262144 /dev/zero > "$target" 2>/dev/null
kill -9 $$
EOF
  fi

  chmod +x "$d"/* || true
}

make_base_fixture() { # $1 sbx — minimal /opt/n8n layout the script requires
  mkdir -p "$1/base/storage" "$1/base/backups" "$1/remote" "$1/log"
  printf 'SQLite format 3 fixture' > "$1/base/storage/database.sqlite"
  printf 'services:\n  n8n:\n    image: n8nio/n8n:1.0.0\n' > "$1/base/docker-compose.yml"
  printf 'N8N_ENCRYPTION_KEY=fixture\n' > "$1/base/.env"
}

make_archive_fixture() { # $1 bk dir  $2 yyyymmdd  $3 with_sidecar(0/1)  [$4 corrupt(0/1)]
  local bk="$1" day="$2" side="$3" corrupt="${4:-0}"
  local src; src=$(mktemp -d)
  printf 'payload %s\n' "$day" > "$src/file.txt"
  if [ "$corrupt" = 1 ]; then
    # a truncated tar (kill mid-write leftover) carrying the FINAL name
    head -c 1024 "$src/file.txt" > "$bk/n8n-migration-$day.tar.gz" 2>/dev/null || true
    printf 'garbage' > "$bk/n8n-migration-$day.tar.gz"
  else
    tar -czf "$bk/n8n-migration-$day.tar.gz" -C "$src" .
    [ "$side" = 1 ] && sha256sum "$bk/n8n-migration-$day.tar.gz" | sed "s|  .*/|  |" > "$bk/n8n-migration-$day.tar.gz.sha256"
  fi
  touch -d "${day:0:4}-${day:4:2}-${day:6:2} 03:00:00" "$bk/n8n-migration-$day.tar.gz"
  rm -rf "$src"
}

run_backup() { # $1 script-copy  $2 shimdir  -> echoes exit code
  PATH="$2:$PATH" bash "$1" >/dev/null 2>&1
  echo $?
}

scenario_a() {
  local sbx; sbx=$(mktemp -d)
  local bk="$sbx/base/backups" remote="$sbx/remote"
  make_base_fixture "$sbx"
  make_archive_fixture "$bk" 20261101 0   # legacy: no sidecar, still good
  make_archive_fixture "$bk" 20261102 1
  make_archive_fixture "$bk" 20261103 1
  make_archive_fixture "$bk" 20261104 1
  make_archive_fixture "$bk" 20261105 1   # will be evicted (oldest with sidecar? no: 01 is oldest)
  make_archive_fixture "$bk" 20261106 1
  make_archive_fixture "$remote" 20261101 0
  make_archive_fixture "$remote" 20261102 0
  make_archive_fixture "$remote" 20261103 0
  make_archive_fixture "$remote" 20261104 0
  make_archive_fixture "$remote" 20261105 0
  make_archive_fixture "$remote" 20261106 0

  local shims testscript
  shims=$(mktemp -d); mk_shims "$shims" 0
  testscript=$(mktestcopy "$sbx")
  local rc; rc=$(run_backup "$testscript" "$shims")

  assert_eq "A: script exit code" "0" "$rc"
  local newcount; newcount=$(ls -1 "$bk"/n8n-migration-*.tar.gz | wc -l | tr -d ' ')
  assert_eq "A: local retention set = KEEP good + just-written (5)" "5" "$newcount"
  assert_files_exist "$bk" "n8n-migration-20261103.tar.gz" "n8n-migration-20261104.tar.gz" \
                            "n8n-migration-20261105.tar.gz" "n8n-migration-20261106.tar.gz"
  [ -e "$bk/n8n-migration-20261101.tar.gz" ] && fail "A: oldest good archive was evicted" \
    || pass "A: pre-KEEP overflow archive pruned"
  [ -e "$bk/n8n-migration-20261102.tar.gz" ] && fail "A: 20261102 (inside KEEP) evicted" \
    || pass "A: no good archive evicted inside KEEP window"

  local newest; newest=$(ls -1t "$bk"/n8n-migration-*.tar.gz | head -1)
  [ -e "$newest.sha256" ] && pass "A: new archive has checksum sidecar" || fail "A: sidecar missing for $newest"
  (cd "$bk" && sha256sum -c "$(basename "$newest").sha256" >/dev/null 2>&1) \
    && pass "A: sidecar verifies against archive" || fail "A: sidecar mismatch"

  local rnew rsha lsha
  rnew=$(ls -1t "$remote"/n8n-migration-*.tar.gz | head -1)
  [ -n "$rnew" ] && pass "A: off-box copy exists" || fail "A: no off-box copy"
  rsha=$(sha256sum "$rnew" | cut -d' ' -f1)
  lsha=$(sha256sum "$newest" | cut -d' ' -f1)
  assert_eq "A: off-box sha256 equals local sha256" "$lsha" "$rsha"
  [ -e "$remote/$(basename "$newest").sha256" ] && pass "A: sidecar copied off-box" || fail "A: sidecar not off-box"
  local rcount; rcount=$(ls -1 "$remote"/n8n-migration-*.tar.gz | wc -l | tr -d ' ')
  assert_eq "A: remote pruned to KEEP (4)" "4" "$rcount"

  local orphans; orphans=$(ls "$bk"/.part-* 2>/dev/null | wc -l | tr -d ' ')
  assert_eq "A: zero .part staging leftovers" "0" "$orphans"
  rm -rf "$sbx" "$shims"
}

scenario_b() {
  local sbx; sbx=$(mktemp -d)
  local bk="$sbx/base/backups" remote="$sbx/remote"
  make_base_fixture "$sbx"
  make_archive_fixture "$bk" 20261101 1
  make_archive_fixture "$bk" 20261102 1
  make_archive_fixture "$bk" 20261103 1
  make_archive_fixture "$bk" 20261104 1
  local before; before=$(ls -1t "$bk"/n8n-migration-*.tar.gz 2>/dev/null)

  local shims testscript
  shims=$(mktemp -d); mk_shims "$shims" 1    # tar shim = kill mid-write
  testscript=$(mktestcopy "$sbx")
  local rc; rc=$(run_backup "$testscript" "$shims")

  case "$rc" in
    0) fail "B: script exited 0 despite kill injection (rc=$rc)";;
    *) pass "B: script died mid-write as injected (rc=$rc)";;
  esac
  local after; after=$(ls -1t "$bk"/n8n-migration-*.tar.gz 2>/dev/null)
  assert_eq "B: retention set byte-identical after killed run" "$before" "$after"
  local parts; parts=$(ls "$bk"/.part-* 2>/dev/null | wc -l | tr -d ' ')
  assert_eq "B: zero partial archives after kill (trap cleaned .part)" "0" "$parts"
  local sides; sides=$(ls "$bk"/n8n-migration-*.sha256 2>/dev/null | wc -l | tr -d ' ')
  assert_eq "B: sidecar count unchanged (4)" "4" "$sides"
  local remote_files; remote_files=$(ls -1 "$remote"/n8n-migration-*.tar.gz 2>/dev/null | wc -l | tr -d ' ')
  assert_eq "B: nothing pushed off-box after kill" "0" "$remote_files"

  # the NEXT good run must still succeed and keep all good archives
  local shims2
  shims2=$(mktemp -d); mk_shims "$shims2" 0
  rc=$(run_backup "$testscript" "$shims2")
  assert_eq "B: recovery run exit code" "0" "$rc"
  local n; n=$(ls -1 "$bk"/n8n-migration-*.tar.gz | wc -l | tr -d ' ')
  assert_eq "B: recovery run leaves 4 good + 1 new = 5" "5" "$n"
  for f in 20261101 20261102 20261103 20261104; do
    [ -e "$bk/n8n-migration-$f.tar.gz" ] || fail "B: good archive $f lost across killed run"
  done
  pass "B: all KEEP good archives survived the killed run"
  rm -rf "$sbx" "$shims" "$shims2"
}

scenario_c() {
  local sbx; sbx=$(mktemp -d)
  local bk="$sbx/base/backups" remote="$sbx/remote"
  make_base_fixture "$sbx"
  make_archive_fixture "$bk" 20261101 1
  make_archive_fixture "$bk" 20261102 1
  make_archive_fixture "$bk" 20261103 1
  make_archive_fixture "$bk" 20261104 1
  # corrupt file carrying the FINAL name, NEWEST by mtime (the H7832 defect shape)
  make_archive_fixture "$bk" 20261107 0 1
  touch -d "2026-11-08 00:00:00" "$bk/n8n-migration-20261107.tar.gz"
  # pre-H7832 leftovers: orphan sidecar + stale .part from a SIGKILLed run
  printf '%s  n8n-migration-20261105.tar.gz\n' deadbeef > "$bk/n8n-migration-20261105.tar.gz.sha256"
  printf 'stale' > "$bk/.part-n8n-migration-20261106.tar.gz"

  local shims testscript
  shims=$(mktemp -d); mk_shims "$shims" 0
  testscript=$(mktestcopy "$sbx")
  local rc; rc=$(run_backup "$testscript" "$shims")

  assert_eq "C: script exit code" "0" "$rc"
  [ -e "$bk/n8n-migration-20261107.tar.gz" ] \
    && fail "C: corrupt final-name archive was kept in the retention set" \
    || pass "C: corrupt final-name archive removed (refused from retention)"
  local n; n=$(ls -1 "$bk"/n8n-migration-*.tar.gz | wc -l | tr -d ' ')
  assert_eq "C: KEEP good + 1 new remain (5)" "5" "$n"
  for f in 20261101 20261102 20261103 20261104; do
    [ -e "$bk/n8n-migration-$f.tar.gz" ] || fail "C: good archive $f evicted by corrupt newcomer"
  done
  pass "C: no good archive evicted in favor of the partial"
  [ -e "$bk/n8n-migration-20261105.tar.gz.sha256" ] \
    && fail "C: orphan sidecar not cleaned" \
    || pass "C: orphan sidecar cleaned"
  [ -e "$bk/.part-n8n-migration-20261106.tar.gz" ] \
    && fail "C: stale .part not cleaned" \
    || pass "C: stale .part staging file cleaned"
  rm -rf "$sbx" "$shims"
}

mktestcopy() { # $1 sbx -> echoes path of path-rewritten script copy
  local sbx="$1" out; out=$(mktemp)
  sed -e "s|^BASE=/opt/n8n$|BASE=\"$sbx/base\"|" \
      -e "s|^LOG=/var/log/n8n-backup.log$|LOG=\"$sbx/log/n8n-backup.log\"|" \
      -e "s|^OFF_DIR=\"/root/n8n-backups\"$|OFF_DIR=\"$sbx/remote\"|" \
      -e "s|^KEEP=14$|KEEP=$KEEP_T|" \
      -e "s|^MIN_FREE_MB=2048$|MIN_FREE_MB=64|" \
      "$SCRIPT" > "$out"
  printf '%s\n' "$out"
}

say "== H7832 failure-injection harness (KEEP=$KEEP_T) =="
df -Pm / >/dev/null 2>&1 || { echo "ABORT: df -Pm unsupported"; exit 2; }
scenario_a
scenario_b
scenario_c
say "== summary: $PASS pass, $FAILURES fail =="
[ "$FAILURES" = 0 ]
