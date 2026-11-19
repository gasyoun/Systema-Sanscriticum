#!/usr/bin/env bash
# test_guard_checks_refuse.sh — deliberate-fail selftest for H7059 on .91.
#
# Каждая проверка обязана ОТКАЗАТЬ на синтетическом нарушении и молчать на
# здоровом входе. Это и есть fail-closed дисциплина: зелёный на пустом входе
# не считается доказательством.
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=/dev/null
. "$HERE/guard_checks.sh"

FAIL=0
expect() { # <label> <expected-substring> <actual>
  local label="$1" want="$2" got="$3"
  if [[ "$got" == *"$want"* ]]; then
    printf 'ok   %s\n' "$label"
  else
    printf 'FAIL %s\n  want: %s\n  got:  %s\n' "$label" "$want" "$got"
    FAIL=1
  fi
}
expect_empty() { # <label> <actual>
  local label="$1" got="$2"
  if [ -z "$got" ]; then printf 'ok   %s\n' "$label"; else printf 'FAIL %s (not empty: %s)\n' "$label" "$got"; FAIL=1; fi
}

# cgroup-budget: at/over memory.max must be critical; healthy must be silent.
expect "cgroup over max refuses" "critical|cgroup-budget|n8n-n8n-1" \
  "$(guard_cgroup_budget n8n-n8n-1 3221225472 3221225472 90)"
expect_empty "cgroup healthy is silent" \
  "$(guard_cgroup_budget n8n-n8n-1 134000000 3221225472 90)"
expect "cgroup warning near max" "warning|cgroup-budget|n8n-n8n-1" \
  "$(guard_cgroup_budget n8n-n8n-1 3000000000 3221225472 90)"

# backup-fresh: a stale destination artifact must be critical; healthy is silent;
# unreadable age must refuse instead of reporting green.
expect "backup destination stale refuses" "critical|backup-fresh|" \
  "$(guard_backup_age "off-site n8n" 40 30)"
expect_empty "backup destination fresh is silent" \
  "$(guard_backup_age "off-site n8n" 12 30)"
expect "backup age unreadable refuses" "critical|backup-fresh|" \
  "$(guard_backup_age "off-site n8n" "" 30)"

# cert-expiry: below threshold must be critical; unreadable must be warning.
expect "cert below threshold refuses" "critical|cert-expiry|" \
  "$(guard_cert_expiry context-ai.ru:443 13 21)"
expect "expired cert refuses" "critical|cert-expiry|" \
  "$(guard_cert_expiry context-ai.ru:443 -2 21)"
expect "unreadable cert is a warning" "warning|cert-expiry|" \
  "$(guard_cert_expiry context-ai.ru:443 '' 21)"
expect_empty "cert healthy is silent" \
  "$(guard_cert_expiry context-ai.ru:443 60 21)"

if [ "$FAIL" -ne 0 ]; then
  printf '\n✖ selftest FAILED — проверка не отказывает на плохом входе\n'
  exit 1
fi
printf '\n✔ selftest passed — все три проверки отказывают на синтетическом нарушении\n'
exit 0
