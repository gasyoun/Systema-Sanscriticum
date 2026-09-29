# Ollama-туннель на `.92`: владелец, живость, перезапуск

_Created: 15-09-2026 · Last updated: 15-09-2026_

**Handoff:** [H4845 (OxAlpha) — Ollama dense-leg reverse-tunnel liveness guard + alert](https://github.com/gasyoun/Uprava/blob/main/handoffs/H4845-OxAlpha_Systema-Sanscriticum_ollama-dense-leg-tunnel-liveness_14.09.26.md)
**Контекст:** [EXPERIMENT_OLLAMA_GPU_OCT1_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/EXPERIMENT_OLLAMA_GPU_OCT1_2026.md) · [SERVER_SOFT_ALERT_PLAYBOOK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SERVER_SOFT_ALERT_PLAYBOOK.md)

## Что это и чьё

1. Модели (`bge-m3:latest`, `qwen3:14b`) живут на **GPU-узле Ивана** (GitHub `@pe4kinsmart-tech`), не на `.92` — ставить модель на `.92` запрещено (issue [#1633](https://github.com/gasyoun/Systema-Sanscriticum/issues/1633)).
2. Узел сам поднимает **reverse-туннель** `autossh -R 11434:localhost:11434` к `.92`. На `.92` от туннеля виден только слушатель `127.0.0.1:11434` / `[::1]:11434`, владелец сокета — `sshd-session`. Процессов `autossh`/`ollama` на `.92` нет и быть не должно.
3. **Владелец туннеля — Иван** (узел, `autossh`, модели). Владелец проверки — этот репозиторий (`cabinet:probe`).
4. Рабочие часы узла — 9–21 МСК. Ночью туннель спит штатно.
5. На `.92` в root-кроне есть `0 9 * * * /root/enable-ollama-shadow.sh` (журнал `/var/log/ollama-shadow-enable.log`): в 09:00 он включает `BOT_OLLAMA_SHADOW`, если туннель жив. **Выключать флаг при смерти туннеля он не умеет**, поэтому флаг остаётся включённым и после обрыва (замер 14-09-2026).

## Кто зависит от туннеля

| Потребитель | Флаг / ключ | Что при мёртвом туннеле |
|---|---|---|
| Dense-нога FAQ-поиска | `KNOWLEDGE_EMBEDDING_DRIVER=ollama` + `FAQ_HYBRID_RETRIEVAL=true` | WARN «HybridRetriever: dense-нога недоступна, деградация в BM25», поиск молча хуже |
| Индексация `knowledge:index` | `KNOWLEDGE_EMBEDDING_DRIVER=ollama` | job падает, `knowledge_chunks` не обновляются |
| Теневая генерация | `BOT_OLLAMA_SHADOW=true` | `SupportAiReplyEvent` `ollama_shadow` со `status=error`; студенту уходит ответ OpenRouter, как обычно |
| Локальная генерация | `BOT_LOCAL_GENERATION=true` | бот отвечает только детерминированно + «позову куратора» (откат во внешний API запрещён) |

## Тревога (H4845)

1. `cabinet:probe` (cron `*/15`) вызывает [`OllamaTunnelProbe`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/ServerGuards/OllamaTunnelProbe.php): `GET {KNOWLEDGE_OLLAMA_BASE_URL}/api/tags`, таймаут `KNOWLEDGE_REQUEST_TIMEOUT`, 2 попытки с паузой 2 с.
2. Проверка идёт, **только** если включён хотя бы один потребитель из таблицы и сейчас рабочее окно `KNOWLEDGE_TUNNEL_HOURS` (по умолчанию `09:30-20:30` МСК, по полчаса запаса с краёв; пусто = круглосуточно).
3. Мёртвый туннель — **одна soft-находка** `ollama-tunnel: …` в TG «Кабинет: soft-сбой (ollama-туннель)». Текст называет класс отказа: `cURL 7` — слушателя нет (туннель мёртв); `cURL 28` — таймаут (туннель висит или узел не отвечает); `HTTP 5xx` — туннель жив, Ollama за ним нет.
4. Повторы не спамят: [`SoftFailureFingerprint`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/ServerGuards/SoftFailureFingerprint.php) сводит все варианты к одному классу `ollama-tunnel`, напоминание раз в `CABINET_PROBE_TELEGRAM_SOFT_REMINDER_HOURS` (24 ч).
5. Туннель жив — находки нет, sticky-состояние снимается. Следующая смерть снова даёт ровно одну тревогу. Это закреплено тестом `test_dead_ollama_tunnel_alerts_once_and_restored_tunnel_clears` в [CabinetProbeTest](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/CabinetProbeTest.php).
6. **Почему soft, а не critical:** студент ответ получает (BM25-пол / OpenRouter / детерминированный) — это деградация, а не «кабинет лежит». Critical разбудил бы SOS и Better Stack `/fail` из-за чужой машины.
7. **Почему не в `guards:verify`:** `deploy.sh` читает любой провал `guards:verify` как «предохранители расходятся, выполните `server_guards_apply.sh`». Туннель к чужому узлу — не предохранитель `.92`, и applier его не чинит.

## Проверить руками (на `.92`, `ssh root@193.232.229.92`)

```sh
ss -tlnp | grep 11434 || echo NO-LISTENER
curl -sS -m3 -o /dev/null -w '%{http_code}\n' http://127.0.0.1:11434/api/tags
grep -E '^(KNOWLEDGE_EMBEDDING_DRIVER|FAQ_HYBRID_RETRIEVAL|BOT_OLLAMA_SHADOW|BOT_LOCAL_GENERATION)=' /var/www/html/.env
```

- `NO-LISTENER` + `curl: (7)` — туннель мёртв. Чинит Иван на узле (ниже).
- Слушатель есть, а `curl` висит (`28`) — зависшая `sshd-session` держит порт. Новый `autossh` с `ExitOnForwardFailure=yes` на занятый порт не встанет. Найдите PID в выводе `ss -tlnp` и снимите **только эту** сессию: `kill <pid>`. После этого узел переподключится сам.
- `200` — туннель жив; тревога снимется на следующем прогоне пробы (≤15 мин).

## Перезапуск (сторона узла — Иван)

Точное имя юнита на узле в этом репозитории не записано (_не проверено_). Рекомендуемая форма — systemd-юнит на узле, чтобы туннель переживал ребут и обрывы:

```sh
autossh -M 0 -N \
  -o ServerAliveInterval=30 -o ServerAliveCountMax=3 \
  -o ExitOnForwardFailure=yes \
  -R 11434:localhost:11434 <user>@193.232.229.92
```

`ServerAliveInterval`/`ServerAliveCountMax` рвут мёртвую сессию за ~90 с. `ExitOnForwardFailure` не даёт висеть «подключённым без порта».

## Заглушить осознанно

- Узел ушёл надолго: выключите потребителей в `.env` на `.92` (`BOT_OLLAMA_SHADOW=false`, `KNOWLEDGE_EMBEDDING_DRIVER=` пусто) и выполните `php artisan config:cache`. Проверка замолчит сама, потому что туннель станет никому не нужен. Поиск при этом останется на BM25.
- Выключить только тревогу, оставив флаги: `CABINET_PROBE_CHECK_OLLAMA_TUNNEL=false` + `config:cache`. Не рекомендуется: это та самая тихая деградация, ради которой существует H4845.

_Гасунс_
