#!/bin/bash
set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SOURCE="$HERE/systema-git-integrity.sh"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

mkdir -p "$TMP/app/.git" "$TMP/bin" "$TMP/brief"
: > "$TMP/baseline"

cat > "$TMP/bin/git" <<'EOF'
#!/bin/bash
for arg in "$@"; do
  case "$arg" in
    rev-parse) exit 0 ;;
    status)
      [ "${FAKE_GIT_MODE:-clean}" = status_fail ] && exit 7
      [ "${FAKE_GIT_MODE:-clean}" = tamper ] && printf '?? bad.php\0'
      exit 0
      ;;
    diff)
      [ "${FAKE_GIT_MODE:-clean}" = diff_fail ] && exit 9
      exit 0
      ;;
  esac
done
exit 0
EOF
chmod +x "$TMP/bin/git"

sed \
  -e "s|@@APP_DIR@@|$TMP/app|g" \
  -e "s|@@GIT_BASELINE_FILE@@|$TMP/baseline|g" \
  -e "s|LOG=/var/log/systema-git-integrity.log|LOG=$TMP/run.log|" \
  -e "s|BRIEF_DIR=/home/hermes/brief|BRIEF_DIR=$TMP/brief|" \
  "$SOURCE" > "$TMP/guard.sh"
chmod +x "$TMP/guard.sh"

FAIL=0
run_case() {
  local mode="$1" expected="$2" marker="$3" rc
  : > "$TMP/run.log"
  PATH="$TMP/bin:$PATH" FAKE_GIT_MODE="$mode" "$TMP/guard.sh" >/dev/null 2>&1
  rc=$?
  if [ "$rc" -ne "$expected" ] || ! grep -qF "$marker" "$TMP/run.log"; then
    echo "FAIL $mode: rc=$rc expected=$expected marker=$marker" >&2
    FAIL=1
  else
    echo "PASS $mode: rc=$rc"
  fi
}

run_case clean 0 'exit=0'
run_case status_fail 1 'git-status-failed rc=7'
run_case diff_fail 1 'git-diff-failed rc=9'
run_case tamper 2 'exit=2'

exit "$FAIL"
