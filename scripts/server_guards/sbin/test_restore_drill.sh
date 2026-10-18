#!/usr/bin/env bash
# test_restore_drill.sh — H6307 behavioral suite for the monthly restore drill
# (scripts/server_guards/sbin/restore_drill.sh).
#
# Runs entirely in a temp dir with a stubbed lane_lib and a stubbed/real restic —
# no prod access, no root, no secrets. Covers the locked acceptance of H6307:
#   1. password-file configuration is honoured (the working lane contract)
#   2. missing RESTIC_PASSWORD_FILE => loud FAIL + P1 page (not a silent skip)
#   3. unreadable RESTIC_PASSWORD_FILE => loud FAIL + P1 page (skipped when run as root)
#   4. restic snapshot command failure => FAIL + P1 page
#   5. restic restore command failure => FAIL + P1 page
#   6. cleanup confined to this run: own scratch removed on every exit path,
#      sibling restic_drill.* dirs of other runs untouched
#   7. legacy literal RESTIC_PASSWORD still honoured (backward compatibility)
#   8. no password value in ANY captured log/report/stdout
#   9. with a real restic: a scratch encrypted fixture restores to a matching
#      sha256 (SKIP-NO-RESTIC when restic is absent — portable-only evidence)
#
# Before/after evidence: run against the legacy on-box script with
#   RESTORE_DRILL_UNDER_TEST=/path/to/old_restore_drill.sh bash test_restore_drill.sh
# The legacy script hardcodes root/hermes paths and only knows RESTIC_PASSWORD,
# so the suite documents the contract break (plus the live 2026-10-01 prod report).
#
# KEEP_TMP=1 preserves the artifact tree for debugging.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="$SCRIPT_DIR/restore_drill.sh"
TARGET="${RESTORE_DRILL_UNDER_TEST:-$SRC}"

TMP="$(mktemp -d)"
cleanup() {
  if [ -n "${KEEP_TMP:-}" ]; then echo "KEEP_TMP artifacts at: $TMP"
  else rm -rf "$TMP"; fi
}
trap cleanup EXIT

pass=0
fail=0
skipped=0
check() {
  if [ "$2" = "1" ]; then pass=$((pass + 1)); echo "PASS: $1"
  else fail=$((fail + 1)); echo "FAIL: $1"; fi
}
skip() { skipped=$((skipped + 1)); echo "SKIP: $1"; }

# ── pipefail-proof assertion helpers (grep -c exits 1 on zero matches!) ───────
count_lines() { # count_lines PATTERN FILE -> prints count, always rc=0
  local n=""
  n="$(grep -cF -- "$1" "$2" 2>/dev/null)" || true
  echo "${n:-0}"
}
has() { # has PATTERN FILE [MIN] -> 1 when count >= MIN (default 1)
  local n min
  n="$(count_lines "$1" "$2")"
  min="${3:-1}"
  [ "$n" -ge "$min" ] && echo 1 || echo 0
}
lacks() { # lacks PATTERN FILE -> 1 when count == 0
  [ "$(count_lines "$1" "$2")" = "0" ] && echo 1 || echo 0
}
file_empty() { [ ! -s "$1" ] && echo 1 || echo 0; }
scratch_clean() { # 1 when no restic_drill.* dir survives except restic_drill.foreign
  local leftovers=0 d
  shopt -s nullglob
  for d in "$TMP/scratch-base"/restic_drill.*; do
    case "$d" in *foreign*) ;; *) leftovers=$((leftovers + 1)) ;; esac
  done
  shopt -u nullglob
  [ "$leftovers" = "0" ] && echo 1 || echo 0
}
foreign_kept() { [ -d "$TMP/scratch-base/restic_drill.foreign" ] && echo 1 || echo 0; }

# ── fixtures ──────────────────────────────────────────────────────────────────
mkdir -p "$TMP/bin" "$TMP/scratch-base" "$TMP/art" "$TMP/brief"

# Stubbed lane library (same shape as the real one, no prod side effects).
cat > "$TMP/lane_lib.sh" <<'LIB'
BRIEF_DIR="${RESTIC_TEST_BRIEF_DIR:?no brief dir}"
page_or_queue() { echo "P$1: $2" >> "$BRIEF_DIR/pages.log"; }
hb_mark() { :; }
LIB

# Secret material — values must NEVER appear in any captured artifact.
PW_FILE_SECRET="S3cr3t-PWFILE-h6307"
PW_LEGACY_SECRET="LegacyLiteral-h6307-secret"
printf '%s\n' "$PW_FILE_SECRET" > "$TMP/pw-file"
printf '%s\n' "$PW_FILE_SECRET" > "$TMP/pw-unreadable"
chmod 000 "$TMP/pw-unreadable"

# Fixture dump the stub/real restic "restores" into the scratch target.
printf 'insert into fixture values (1);\n' | gzip > "$TMP/var-backups-dump.sql.gz"
FIXTURE_SHA="$( (command -v sha256sum >/dev/null && sha256sum "$TMP/var-backups-dump.sql.gz" || shasum -a 256 "$TMP/var-backups-dump.sql.gz") | awk '{print $1}')"

# Stub restic: ok | fail-snap | fail-restore; logs every argv for leak/args checks.
cat > "$TMP/bin/restic" <<STUB
#!/usr/bin/env bash
echo "restic \$*" >> "\${RESTIC_TEST_ARGS_LOG:?}"
case "\${STUB_MODE:-ok}" in
  fail-snap) exit 1 ;;
  fail-restore)
    case " \$* " in *" snapshots "*) echo '{"id":"cafebabe"}'; exit 0 ;; esac
    echo "stub restore explosion" >&2
    exit 7 ;;
esac
case " \$* " in
  *" snapshots "*)
    echo '{"id":"deadbeef"}'
    exit 0 ;;
  *" restore "*)
    tgt=""
    prev=""
    for a in "\$@"; do
      if [ "\$prev" = "--target" ]; then tgt="\$a"; fi
      prev="\$a"
    done
    if [ -n "\$tgt" ] && [ -n "\${STUB_FIXTURE:-}" ]; then
      mkdir -p "\$tgt/var/backups"
      cp "\$STUB_FIXTURE" "\$tgt/var/backups/dump.sql.gz"
    fi
    echo "restored 1 files"
    exit 0 ;;
esac
exit 0
STUB
chmod +x "$TMP/bin/restic"

ART=""
new_case() {
  ART="$TMP/art/$1"
  mkdir -p "$ART"
  rm -f "$TMP/brief/pages.log"
  : > "$TMP/brief/drill_latest.md"
}

# Run the drill under test against the case env; capture every artifact.
# RESTIC_TEST_REAL_RESTIC=1 keeps the REAL restic binary on PATH (case 9);
# without it the stub restic answers every call.
run_drill() {
  : > "$TMP/args.log"
  (
    if [ -n "${RESTIC_TEST_REAL_RESTIC:-}" ]; then
      export PATH="$(dirname "$(command -v restic)"):$PATH"
    else
      export PATH="$TMP/bin:$PATH"
    fi
    export RESTIC_DRILL_LANE_LIB="$TMP/lane_lib.sh"
    export RESTIC_DRILL_TMP_BASE="$TMP/scratch-base"
    export RESTIC_DRILL_ENV="$TMP/drill.env"
    export RESTIC_DRILL_INCLUDE="${RESTIC_TEST_INCLUDE:-/var/backups/*.gz}"
    export RESTIC_TEST_BRIEF_DIR="$TMP/brief"
    export RESTIC_TEST_ARGS_LOG="$TMP/args.log"
    unset RESTIC_PASSWORD RESTIC_PASSWORD_FILE RESTIC_REPOSITORY STUB_MODE STUB_FIXTURE
    if [ -z "${RESTIC_TEST_REAL_RESTIC:-}" ]; then
      export STUB_MODE="${RESTIC_TEST_STUB_MODE:-ok}"
      export STUB_FIXTURE="${RESTIC_TEST_FIXTURE:-$TMP/var-backups-dump.sql.gz}"
    fi
    bash "$TARGET"
  ) > "$ART/run.out" 2> "$ART/run.err"
  cp "$TMP/brief/drill_latest.md" "$ART/report.md"
  if [ -f "$TMP/brief/pages.log" ]; then cp "$TMP/brief/pages.log" "$ART/pages.log"
  else : > "$ART/pages.log"; fi
  cp "$TMP/args.log" "$ART/restic-args.log"
}

write_env() { # write_env uses the two named exports given as lines
  cat > "$TMP/drill.env"
}

# ── 1. password-file configuration ────────────────────────────────────────────
new_case "file-contract-green"
write_env <<ENV
export RESTIC_REPOSITORY=$TMP/fake-repo
export RESTIC_PASSWORD_FILE=$TMP/pw-file
ENV
run_drill
check "1a file contract: drill proceeds (no SKIPPED-NO-ACCESS)" "$(lacks 'SKIPPED-NO-ACCESS' "$ART/report.md")"
check "1b file contract: names the FILE contract source" "$(has 'пароль взят из RESTIC_PASSWORD_FILE' "$ART/report.md")"
check "1c file contract: restores from the resolved repo" "$(has "-r $TMP/fake-repo" "$ART/restic-args.log" 2)"
check "1d file contract: fresh snapshot answered (GREEN line)" "$(has 'свежайший снапшот deadbeef' "$ART/report.md")"
check "1e file contract: scratch cleaned on success" "$(scratch_clean)"

# ── 2. missing password file ──────────────────────────────────────────────────
new_case "password-file-missing"
write_env <<ENV
export RESTIC_REPOSITORY=$TMP/fake-repo
export RESTIC_PASSWORD_FILE=$TMP/no-such-file
ENV
run_drill
check "2a missing file: loud FAIL with reason" "$(has 'RESTIC_PASSWORD_FILE задан (missing)' "$ART/report.md")"
check "2b missing file: P1 page fired" "$(has 'P1:' "$ART/pages.log")"
check "2c missing file: restic never invoked" "$(file_empty "$ART/restic-args.log")"

# ── 3. unreadable password file (skip when root: root reads anything) ─────────
if [ "$(id -u)" = "0" ]; then
  skip "3 unreadable-file case (running as root — -r is always true; CI runs non-root)"
else
  new_case "password-file-unreadable"
  write_env <<ENV
export RESTIC_REPOSITORY=$TMP/fake-repo
export RESTIC_PASSWORD_FILE=$TMP/pw-unreadable
ENV
  run_drill
  check "3a unreadable file: loud FAIL with reason" "$(has 'RESTIC_PASSWORD_FILE задан (unreadable)' "$ART/report.md")"
  check "3b unreadable file: P1 page fired" "$(has 'P1:' "$ART/pages.log")"
fi

# ── 4. restic snapshot command failure + cleanup confinement ──────────────────
mkdir -p "$TMP/scratch-base/restic_drill.foreign"
new_case "restic-snap-failure"
write_env <<ENV
export RESTIC_REPOSITORY=$TMP/fake-repo
export RESTIC_PASSWORD_FILE=$TMP/pw-file
ENV
RESTIC_TEST_STUB_MODE=fail-snap run_drill
check "4a snap failure: loud FAIL" "$(has 'не ответил списком снапшотов' "$ART/report.md")"
check "4b snap failure: P1 page fired" "$(has 'P1:' "$ART/pages.log")"
check "4c snap failure: cleanup confined — own scratch gone" "$(scratch_clean)"
check "4d snap failure: foreign sibling dir untouched" "$(foreign_kept)"

# ── 5. restic restore command failure ─────────────────────────────────────────
new_case "restic-restore-failure"
write_env <<ENV
export RESTIC_REPOSITORY=$TMP/fake-repo
export RESTIC_PASSWORD_FILE=$TMP/pw-file
ENV
RESTIC_TEST_STUB_MODE=fail-restore run_drill
check "5a restore failure: loud FAIL naming the snapshot" "$(has 'restore завершился ошибкой' "$ART/report.md")"
check "5b restore failure: P1 page fired" "$(has 'P1:' "$ART/pages.log")"
check "5c restore failure: scratch cleaned on failure path" "$(scratch_clean)"
check "5d restore failure: foreign sibling dir untouched" "$(foreign_kept)"

# ── 7. legacy literal RESTIC_PASSWORD contract still works ────────────────────
new_case "legacy-literal-password"
write_env <<ENV
export RESTIC_REPOSITORY=$TMP/fake-repo
export RESTIC_PASSWORD=$PW_LEGACY_SECRET
ENV
run_drill
check "7a legacy literal: drill proceeds" "$(lacks 'SKIPPED-NO-ACCESS' "$ART/report.md")"
check "7b legacy literal: restic invoked with resolved repo" "$(has "-r $TMP/fake-repo" "$ART/restic-args.log" 2)"

# ── 9. real restic: encrypted scratch fixture restores to matching sha256 ─────
if command -v restic >/dev/null 2>&1; then
  new_case "real-restic-fixture-hash"
  REAL_REPO="$TMP/real-repo"
  printf 'real-restic-pw-h6307\n' > "$TMP/real-pw"
  chmod 600 "$TMP/real-pw"
  mkdir -p "$TMP/real-src/var/backups"
  printf 'create table fixture_h6307;\n' | gzip > "$TMP/real-src/var/backups/dump.sql.gz"
  EXPECTED_SHA="$( (command -v sha256sum >/dev/null && sha256sum "$TMP/real-src/var/backups/dump.sql.gz" || shasum -a 256 "$TMP/real-src/var/backups/dump.sql.gz") | awk '{print $1}')"
  if restic -r "$REAL_REPO" init --password-file "$TMP/real-pw" >/dev/null 2>&1 \
    && restic -r "$REAL_REPO" --password-file "$TMP/real-pw" backup "$TMP/real-src" >/dev/null 2>&1; then
    write_env <<ENV
export RESTIC_REPOSITORY=$REAL_REPO
export RESTIC_PASSWORD_FILE=$TMP/real-pw
ENV
    RESTIC_TEST_REAL_RESTIC=1 RESTIC_TEST_INCLUDE='**/var/backups/*.gz' run_drill
    check "9a real restic: fixture restored through the drill" "$(has 'файлов восстановлено: 1' "$ART/report.md")"
    check "9b real restic: scratch fixture hash matches the source fixture" "$(has "sha256=$EXPECTED_SHA" "$ART/report.md")"
    check "9c real restic: gzip integrity check ran" "$(has 'целостность сэмпла OK' "$ART/report.md")"
  else
    skip "9 real-restic case (local restic init/backup failed — environment issue, not the drill)"
  fi
else
  skip "9 real-restic case — SKIP-NO-RESTIC: portable-only evidence without a restic binary"
fi

# ── 8. no password value anywhere in captured artifacts (sweep last) ──────────
leaks=0
for secret in "$PW_FILE_SECRET" "$PW_LEGACY_SECRET"; do
  for f in "$TMP"/art/*/*; do
    [ -f "$f" ] || continue
    if grep -qF "$secret" "$f" 2>/dev/null; then
      leaks=$((leaks + 1))
      echo "  leak: $secret found in $f"
    fi
  done
done
check "8 no-password-in-logs: zero leaks across all artifacts" "$([ "$leaks" = "0" ] && echo 1 || echo 0)"

echo "----"
echo "under test: $TARGET"
echo "summary: pass=$pass fail=$fail skipped=$skipped"
[ "$fail" -eq 0 ]
