# GUARD_BRIEF_DIR isolation + MG «never again» ruling — 12-09-2026 (danger fact)

MG ruling 12-09-2026, verbatim: **«Remove deploy lane if it fucks up my work. Never again, remember, fuck up my work.»**

Trigger: 14:31Z false tamper 🚨 — sandbox/verify run of the H4611 guard deploy (interactive Codex Desktop session of MG himself, merge PR #2506 → deploy .92 in 23 s) wrote its FAIL brief (fake `?? bad.php`, tmp baseline `/tmp/tmp.EidiWYnCsY/`) into the LIVE `/home/hermes/brief/git_integrity_latest.md` → pager fired. Webroot was clean (0 nginx hits for bad.php; live guard re-run GREEN 15:47Z).

DANGER FACTS (never do these again):

1. **Sandbox/verification runs of server guards MUST set `GUARD_BRIEF_DIR`** (env override honoured by systema-git-integrity.sh and systema-tamper-watch.sh since H4620). Never let a verify/sandbox write `/home/hermes/brief/` — that dir is a live alarm surface; whatever lands there pages MG.
2. **No standing deploy lane exists for server guards** — deploys are per-session manual/agent actions (verified 12-09: 83 scheduled tasks, hermes lanes, drain pools — none deploy guards). Do not invent one without MG.
3. **Attribution discipline**: before telling MG «это ваш лэйн», probe the SSH key fingerprint against both boxes' keys (46.8.140.22 egress is SHARED by both boxes — IP alone attributes nothing). The 14:29 session key `dZglj…` = Windows box `~/.ssh/id_ed25519`.

Fix: H4620 — `BRIEF_DIR="${GUARD_BRIEF_DIR:-${BRIEF_DIR:-/home/hermes/brief}}"` in both guards, test sets the env; deployed to .92 with live repro.
