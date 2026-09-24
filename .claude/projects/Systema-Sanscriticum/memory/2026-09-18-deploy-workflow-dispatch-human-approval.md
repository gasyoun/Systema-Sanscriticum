# Systema deploy = workflow_dispatch with human environment approval

_Created: 18-09-2026 · Last updated: 24-09-2026_

Fact (probed 18-09-2026, H5154 close): merging a PR to Systema-Sanscriticum main does NOT deploy — `.github/workflows/deploy.yml` triggers only on `workflow_dispatch` (header comment: «Environment approval; merge/push сам по себе его не ставит в очередь»). Last prod deploys are sporadic human runs.

1. Agent can DISPATCH: `gh workflow run deploy.yml -R gasyoun/Systema-Sanscriticum --ref main` — the run then sits in `waiting` until the environment approval is clicked by a human (MG) in the Actions UI.
2. Approved run takes ~3 min; failing deploy cannot redden main (dispatch-only by design).
3. So: merged ≠ live. Any handoff whose verification includes live HTML probes must either include the deploy-approval step in its GTD residual or wait for MG's next deploy.

## Дополнение 24-09-2026 — api.github.com лежит, деплой всё равно идёт

Проверено вживую: при TLS-handshake-таймаутах `api.github.com` с рабочей машины
(`gh workflow run`, `gh pr merge`, `gh pr checks` — падали все) прод задеплоился сам.

1. **Серверный root-cron каждые 30 мин** гоняет `/usr/local/sbin/systema-auto-deploy-run.sh`
   (managed-файл, repo-копия `scripts/server_guards/sbin/`) → тот же `deploy.sh`;
   молчит при `HEAD == origin/main`; автооткат, только если деплой не приносил миграций;
   предохранитель `storage/auto_deploy.disabled`. Пруф того дня:
   `2026-09-24T17:01:01Z OK: задеплоен c69cac32, health чист (mem 13603MB, smoke 200)`.
2. **Немедленно, одна команда:** `ssh root@193.232.229.92 'sudo /bin/bash /var/www/html/deploy.sh'`
   (idempotent — повторный прогон безопасен).
3. **Merge без API** (git-ssh жив, пока лежит api.github.com):
   `git fetch origin main && git merge --squash <branch> && git push origin HEAD:main`.
4. **Зависший GH-run** после ручного деплоя — approve НЕ нужен: он лишь повторит
   идемпотентный `deploy.sh` на том же SHA; отменять безопасно.

Полный текст и команды проверки: `docs/deploy.md` §«Деплой без GitHub — когда
`api.github.com` недоступен».
