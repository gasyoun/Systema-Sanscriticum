#!/usr/bin/env python3
"""PostToolUse autosave hook - Systema-Sanscriticum watcher defense.

A confirmed external watcher process (not a git hook) reverts uncommitted
working-tree changes in this repo; content committed to HEAD survives.
This hook commits every file touched by Write/Edit immediately, before the
watcher can revert it. Registered in .claude/settings.json
(PostToolUse, matcher Write|Edit).

Commits are pathspec-limited to the touched file only, so unrelated staged
changes (a parallel session's index state) are never swept into the
autosave commit.
"""
import json
import os
import subprocess
import sys

sys.stdout.reconfigure(encoding='utf-8')
sys.stderr.reconfigure(encoding='utf-8')

# .claude/hooks/watcher_autosave.py -> repo root two levels up
REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))


def main():
    try:
        data = json.load(sys.stdin)
    except Exception:
        return 0
    tool_input = data.get('tool_input') or {}
    tool_response = data.get('tool_response') or {}
    fp = tool_input.get('file_path') or tool_response.get('filePath')
    if not fp:
        return 0
    add = subprocess.run(['git', '-C', REPO, 'add', '--', fp],
                         capture_output=True, text=True, encoding='utf-8')
    if add.returncode != 0:
        return 0  # outside the repo or gitignored - nothing to protect
    staged = subprocess.run(['git', '-C', REPO, 'diff', '--cached', '--quiet', '--', fp])
    if staged.returncode == 0:
        return 0  # identical to HEAD - no autosave needed
    msg = 'ai-wip: watcher-safe autosave ' + os.path.basename(fp)
    subprocess.run(['git', '-C', REPO, 'commit', '-m', msg, '--', fp],
                   capture_output=True, text=True, encoding='utf-8')
    return 0


if __name__ == '__main__':
    sys.exit(main())
