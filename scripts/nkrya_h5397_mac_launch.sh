#!/bin/bash
# H5397 — launch the NKRYa band pass on the Mac (the box holding the
# `ruscorpora-api` keychain item). Idempotent: refuses if a run is already live.
#
# RUN IT FROM A TERMINAL WINDOW ON THE MAC ITSELF, not over ssh:
#
#   bash ~/Documents/GitHub/Systema-Sanscriticum/scripts/nkrya_h5397_mac_launch.sh
#
# Probed 24-09-2026 (H5397): over ssh the keychain read fails with rc=36
# (errSecInteractionNotAllowed) — the item exists (created 23-09-2026, acct
# "mac") but a non-GUI session may not read it, `launchctl asuser $(id -u)`
# needs root ("Could not switch to audit session"), and sudo needs a password.
# A GUI Terminal inherits the unlocked login keychain and just works. Once
# launched it survives, so ssh is fine for watching the log afterwards.
set -euo pipefail

GH="$HOME/Documents/GitHub"
WT="$GH/Systema-Sanscriticum-h5397-mac"
PY=/opt/homebrew/bin/python3.13          # the only python here with pymorphy3
LOG="$HOME/nkrya_h5397.log"

if pgrep -f "nkrya_gloss_lint.py" >/dev/null 2>&1; then
  echo "ALREADY_RUNNING"; pgrep -fl "nkrya_gloss_lint.py"; exit 0
fi

if [ -z "${RUSCORPORA_API_TOKEN:-}" ]; then
  TOKEN="$(security find-generic-password -s ruscorpora-api -w 2>/dev/null || true)"
  [ -n "$TOKEN" ] || {
    echo "NO_TOKEN — the keychain refused the read (rc=36 over ssh)."
    echo "Run this script from a Terminal window ON the Mac, not over ssh."
    exit 1
  }
  export RUSCORPORA_API_TOKEN="$TOKEN"
fi

if [ ! -d "$WT" ]; then
  git -C "$GH/Systema-Sanscriticum" fetch origin --quiet
  git -C "$GH/Systema-Sanscriticum" worktree add -b h5397-nkrya-mac "$WT" origin/main
fi

cd "$WT"
nohup "$PY" scripts/nkrya_gloss_lint.py lemmas roots >>"$LOG" 2>&1 &
echo "LAUNCHED pid=$! log=$LOG worktree=$WT"
