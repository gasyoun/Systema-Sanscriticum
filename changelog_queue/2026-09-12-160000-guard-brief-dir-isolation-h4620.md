_Created: 12-09-2026 · Last updated: 12-09-2026_

# H4620: sandbox-прогоны гвардов больше не пишут в живой бриф — GUARD_BRIEF_DIR env-изоляция (OxAlpha z-ai/glm-5.3-flash, 12-09-2026)

Инцидент 14:31 UTC: верификация деплоя H4611 (интерактивная Codex Desktop-сессия, merge PR #2506 → deploy .92 за 23 с) запустила sandbox-кейс тампера; её FAIL-бриф (фейковый `?? bad.php`, tmp-базис) записался в живой `/home/hermes/brief/git_integrity_latest.md` → ложная 🚨-страница MG. Webroot .92 чист (0 HTTP-хитов bad.php, ре-прогон GREEN 15:47Z). Рулинг MG: «Remove deploy lane if it fucks up my work. Never again».

- **[systema-git-integrity.sh](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards/sbin/systema-git-integrity.sh)** и **[systema-tamper-watch.sh](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards/sbin/systema-tamper-watch.sh)**: после инициализации BRIEF_DIR добавлен override `BRIEF_DIR="${GUARD_BRIEF_DIR:-${BRIEF_DIR:-/home/hermes/brief}}"` — sandbox/verifier-прогон выставляет `GUARD_BRIEF_DIR` и физически не может затереть живой бриф или очередь страниц.
- **[test_systema_git_integrity.sh](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards/sbin/test_systema_git_integrity.sh)**: run_case выставляет `GUARD_BRIEF_DIR="$TMP/brief"` (двойная изоляция вместе с sed).
- Улики ложной тревоги: бриф 14:31:32Z с tmp-базисом `/tmp/tmp.EidiWYnCsY/`, 0 HTTP-хитов `bad.php` в nginx, ре-прогон гварда GREEN 15:47:33Z; полная запись — GTD 12-09 (Uprava) и memory-note репозитория.
_Dr. Mārcis Gasūns_
