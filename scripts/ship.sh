#!/usr/bin/env bash
# ship — one-command "branch + push + PR + auto-merge" for hot-main repos.
#
# Usage:
#   ship "commit message / PR title"      # commit all changes, push branch, open PR, auto-merge
#   ship                                  # no new commit: ship commits already on the current branch
#
# Options:
#   -b, --branch NAME   explicit branch name (default: ship/<timestamp>[-slug])
#   --body TEXT         PR body (default: title)
#   --base BRANCH       base branch (default: origin/HEAD, usually main)
#   --no-delete-branch  keep the branch after merge
#
# Behaviour:
#   * must run inside a git repo with GitHub remote and `gh` authenticated
#   * on the base branch: creates a fresh ship/* branch first
#   * uncommitted changes are committed with the given message (git add -A)
#   * tries squash auto-merge; if the repo/plan forbids it, merges immediately
#     with a bounded rebase-and-retry loop (up to 3 attempts)
#   * returns the working tree to the base branch (ff-only pull) at the end
#
# Install:  cp ship ~/bin/ship && chmod +x ~/bin/ship

set -euo pipefail

TITLE=""
BODY=""
BRANCH=""
BASE=""
DELETE_BRANCH=1

while [ $# -gt 0 ]; do
  case "$1" in
    -b|--branch) BRANCH="$2"; shift 2 ;;
    --body) BODY="$2"; shift 2 ;;
    --base) BASE="$2"; shift 2 ;;
    --no-delete-branch) DELETE_BRANCH=0; shift ;;
    -h|--help) sed -n '2,22p' "$0"; exit 0 ;;
    *) TITLE="$1"; shift ;;
  esac
done

die() { echo "ship: $*" >&2; exit 1; }

git rev-parse --is-inside-work-tree >/dev/null 2>&1 || die "not a git repository"
command -v gh >/dev/null 2>&1 || die "gh CLI not found"
gh auth status >/dev/null 2>&1 || die "gh is not authenticated (gh auth login)"

# Base branch: explicit --base, else origin/HEAD, else main
if [ -z "$BASE" ]; then
  BASE=$(git symbolic-ref --quiet --short refs/remotes/origin/HEAD 2>/dev/null | sed 's|^origin/||' || true)
  BASE="${BASE:-main}"
fi

git fetch origin "$BASE" --quiet

CURRENT=$(git branch --show-current)
[ -n "$CURRENT" ] || die "detached HEAD — checkout a branch first"

if [ -n "$(git status --porcelain)" ] && [ -z "$TITLE" ]; then
  die "uncommitted changes but no message given — run: ship \"message\""
fi

# On the base branch with work to ship: move onto a fresh branch
if [ "$CURRENT" = "$BASE" ]; then
  if [ -z "$BRANCH" ]; then
    SLUG=$(printf '%s' "$TITLE" | tr '[:upper:]' '[:lower:]' \
      | sed -E 's/[^a-z0-9]+/-/g; s/^-+|-+$//g' | cut -c1-40)
    BRANCH="ship/$(date +%Y%m%d-%H%M%S)${SLUG:+-$SLUG}"
  fi
  git checkout -b "$BRANCH"
else
  BRANCH="$CURRENT"
fi

# Commit pending changes
if [ -n "$(git status --porcelain)" ]; then
  git add -A
  git commit --quiet -m "$TITLE"
fi

# Anything to ship?
AHEAD=$(git rev-list --count "origin/$BASE..HEAD")
if [ "$AHEAD" -eq 0 ] && ! gh pr view "$BRANCH" --json number >/dev/null 2>&1; then
  die "nothing to ship: branch '$BRANCH' is not ahead of origin/$BASE"
fi

[ -n "$TITLE" ] || TITLE=$(git log -1 --pretty=%s)
[ -n "$BODY" ] || BODY="$TITLE"

git push -u origin "$BRANCH" --quiet

# Open PR if none exists for this branch yet
if gh pr view "$BRANCH" --json number >/dev/null 2>&1; then
  PR_URL=$(gh pr view "$BRANCH" --json url --jq .url)
  echo "ship: PR already exists: $PR_URL"
else
  PR_URL=$(gh pr create --base "$BASE" --head "$BRANCH" --title "$TITLE" --body "$BODY")
  echo "ship: PR created: $PR_URL"
fi

# Merge: prefer auto-merge (fire and forget). Fallback for repos/plans where
# auto-merge is unavailable (e.g. private repo on GitHub Free): immediate
# merge with a bounded rebase-and-retry loop — on a repo without required
# checks this lands the PR in the same run.
MERGE_ARGS=(--auto --squash)
[ "$DELETE_BRANCH" -eq 1 ] && MERGE_ARGS+=(--delete-branch)
if gh pr merge "$BRANCH" "${MERGE_ARGS[@]}" 2>/dev/null; then
  sleep 3
  STATE=$(gh pr view "$BRANCH" --json state --jq .state 2>/dev/null || echo "")
  if [ "$STATE" = "MERGED" ]; then
    echo "ship: merged immediately (no pending checks to wait for)"
  else
    echo "ship: auto-merge armed (squash) — PR will land by itself"
  fi
else
  echo "ship: auto-merge unavailable here — trying immediate merge" >&2
  MERGED=0
  for attempt in 1 2 3; do
    IMM_ARGS=(--squash)
    [ "$DELETE_BRANCH" -eq 1 ] && IMM_ARGS+=(--delete-branch)
    if gh pr merge "$BRANCH" "${IMM_ARGS[@]}" 2>/dev/null; then
      MERGED=1
      echo "ship: merged (attempt $attempt)"
      break
    fi
    # Lost the race or unmergeable: rebase onto fresh base and retry
    git fetch origin "$BASE" --quiet
    if ! git rebase "origin/$BASE" >/dev/null 2>&1; then
      git rebase --abort >/dev/null 2>&1 || true
      echo "ship: rebase conflict — PR stays open for manual resolution: $PR_URL" >&2
      break
    fi
    git push --force-with-lease origin "$BRANCH" --quiet
    sleep 2   # let GitHub recompute mergeability
  done
  if [ "$MERGED" -eq 0 ]; then
    echo "ship: WARNING: not merged after retries — PR stays open: $PR_URL" >&2
  fi
fi

# Return the tree to a fresh base branch (best effort: in a linked worktree
# the base branch is checked out in the main tree — stay on the branch then)
if git checkout --quiet "$BASE" 2>/dev/null; then
  git pull --ff-only origin "$BASE" --quiet || true
  # Hygiene: drop local ship/* branches whose work is already in base
  git branch --merged "$BASE" | sed 's/^[* ]*//' | grep '^ship/' \
    | grep -vx "$BRANCH" | xargs -r git branch -d >/dev/null 2>&1 || true
  echo "ship: done — back on $BASE, PR in flight: $PR_URL"
else
  echo "ship: done — staying on $BRANCH (base checked out elsewhere), PR in flight: $PR_URL"
fi
