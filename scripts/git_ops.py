#!/usr/bin/env python3
"""Minimal stand-in for Uprava ``tools/git_ops.py`` — the exec() seam eol_census needs.

H4362 (08-09-2026): #2429 ported ``scripts/eol_census.py`` from Uprava, where it sits
beside ``tools/git_ops.py``; the module itself was not ported, so every ``git push``
died in the pre-push CRLF gate with ``ModuleNotFoundError: git_ops`` and BLOCKED
without a verdict. The Uprava original pulls in ``pyfloor`` + ``_common`` and is not
droppable here as-is; this file implements the one contract eol_census uses —
``GitOperations().exec(repo, args, timeout_s=, text=, input_bytes=)`` returning an
object with ``ok`` / ``exit_code`` / ``stdout`` / ``stderr``, raising ``GitTimeout``.

If the real module is ever vendored, replace this file with it: the call site is
unchanged.
"""
from __future__ import annotations

import subprocess
from pathlib import Path

DEFAULT_TIMEOUT_S = 60.0


class GitTimeout(RuntimeError):
    """git exceeded ``timeout_s`` — surfaced, never swallowed into an empty result."""


class Result:
    __slots__ = ("exit_code", "ok", "stdout", "stderr")

    def __init__(self, cp: "subprocess.CompletedProcess") -> None:
        self.exit_code = cp.returncode
        self.ok = cp.returncode == 0
        self.stdout = cp.stdout
        err = cp.stderr
        self.stderr = err if isinstance(err, str) or err is None else err.decode("utf-8", "replace")


class GitOperations:
    def exec(self, repo_path, args, *, timeout_s: float = DEFAULT_TIMEOUT_S,
             text: bool = True, input_bytes: "bytes | None" = None, **_ignored) -> Result:
        kwargs = {"encoding": "utf-8", "errors": "replace"} if text else {}
        try:
            cp = subprocess.run(
                ["git", "-C", str(Path(repo_path)), *list(args)],
                input=input_bytes, capture_output=True, timeout=timeout_s, **kwargs,
            )
        except subprocess.TimeoutExpired as exc:
            raise GitTimeout(f"git {' '.join(args)} timed out after {timeout_s}s") from exc
        return Result(cp)
