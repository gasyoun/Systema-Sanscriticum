# Внешний свидетель SOCKS-канала socks-nl на .91 (H8564)

_Created: 23-11-2026 · Last updated: 23-11-2026_

_Задача: [H8564](https://github.com/gasyoun/Uprava/blob/main/handoffs/H8564-OxAlpha_Systema-Sanscriticum_socks91-external-watch_23.11.26.md) · инцидент: [#4429](https://github.com/gasyoun/Systema-Sanscriticum/issues/4429) п.2._

## Зачем

22-11-2026 16:54–17:19 UTC три webhook-исполнения ZOOM+АДМИНКА упали с `ECONNRESET`: privoxy гнал `googleapis.com` через `ssh -D 127.0.0.1:1080` (socks-nl), форвардинг туннеля был мёртв, **а systemd при этом докладывал `active`**, TCP-порт слушал, keepalive'ы отвечали.

Заведённая тогда `socks-liveness.timer` (`socks-liveness-check.sh`, .91, такт 5 мин) чинит эту слепоту: она делает настоящий SOCKS CONNECT и лечит одним рестартом. Но она делит судьбу с подопечным — то, что H1904 запрещает. **Чего проба не может доказать о себе:**

- её таймер остановлен (правка, `systemctl mask`, неудачный деплой);
- сама .91 мертва или недоступна.

Обе причины выглядят снаружи одинаково — тишиной. Поэтому каждый **успешный** прогон пушит метку ОФФ-БОКС, а второй, независимый читатель на .92 кричит, если метка замолчала.

## Архитектура

```
 .91 samskrtam50 (n8n)                                  .92 samskrtam150 (прод)
 ─────────────────────                                  ──────────────────────
 socks-liveness.timer  (5 мин, свой таймер)
   └─ socks-liveness-check.sh
        ├─ SOCKS CONNECT 127.0.0.1:1080 → 200/000
        ├─ [OK]  ssh -i id_socks91_witness ─────────► socks91-witness@.92
        │        (forced command: touch)              /var/lib/systema-guards/socks91_hub.last
        └─ [FAIL] ssh root@.92 ─────────────────────► socks-alarm-relay.sh (ТЕКСТ, без секретов)
                                                        └─ uprava_alert «Вестник»
                                                           → @samskrte_bot → «Отдел заботы»

                                                        systema-socks91-watch.timer (5 мин)
                                                          └─ systema-socks91-watch.sh
                                                               ├─ метка ≤ 900 c → GREEN в brief
                                                               └─ тишина → страница (дедуп 3600 c)
                                                                  через uprava_alert
```

Две оси независимы: **крик о поломке** рождается на .91 (нужен живой .91), **крик о тишине .91** — на .92 (нужен живой .92). Ни один конец не терминатор.

## Файлы (всё управляемое, из репозитория)

| Файл в репозитории | Куда ставится | Профиль |
|---|---|---|
| [`scripts/server_guards_n8n/sbin/socks-liveness-check.sh`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards_n8n/sbin/socks-liveness-check.sh) | `/usr/local/sbin/socks-liveness-check.sh` (.91) | n8n |
| [`scripts/server_guards_n8n/systemd/socks-liveness.{service,timer}`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/scripts/server_guards_n8n/systemd) | `/etc/systemd/system/` (.91) | n8n |
| [`scripts/server_guards/sbin/systema-socks91-watch.sh`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards/sbin/systema-socks91-watch.sh) | `/usr/local/sbin/systema-socks91-watch.sh` (.92) | app |
| [`scripts/server_guards/systemd/systema-socks91-watch.{service,timer}`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/scripts/server_guards/systemd) | `/etc/systemd/system/` (.92) | app |
| [`scripts/server_guards/sbin/socks-alarm-relay.sh`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards/sbin/socks-alarm-relay.sh) | `/usr/local/sbin/socks-alarm-relay.sh` (.92) | app |

До H8564 все шесть жили **только на машинах**: пересборка LXC вернула бы туннель без единого читателя, молча.

Числа (порог тишины, окно дедупа, такт) — в коде с env-швами `SOCKS91_*`; это наблюдаемость, а не предохранитель, поэтому в `server_guards*.conf` они не заведены.

## Развёртывание (однократные шаги, которые НЕ делает apply)

1. **Ключ свидетеля.** На .91: `ssh-keygen -t ed25519 -N "" -C h8564-91-socks91-witness-push -f /root/.ssh/id_socks91_witness`.
2. **Пользователь-приёмник на .92** — forced command, не шелл:

   ```
   useradd --system --create-home --shell /bin/sh socks91-witness
   # /home/socks91-witness/.ssh/authorized_keys (600):
   command="touch /var/lib/systema-guards/socks91_hub.last",no-port-forwarding,no-X11-forwarding,no-agent-forwarding,no-pty ssh-ed25519 AAAA… h8564-91-socks91-witness-push
   touch /var/lib/systema-guards/socks91_hub.last
   chown socks91-witness:socks91-witness /var/lib/systema-guards/socks91_hub.last
   ```

   Метку **pre-create + chown**: forced `touch` идёт от `socks91-witness`, а каталог принадлежит root.
3. **Канал крика на .92** (канон H4686/C8, рулинг MG 23-11-2026 — «Отдел заботы», не личный чат MG):
   `/usr/local/bin/uprava_alert` ← копия [Uprava tools/uprava_alert.sh](https://github.com/gasyoun/Uprava/blob/main/tools/uprava_alert.sh) (пин: коммит `0bad2505d89a`, sha256 `dae6d7e162114ab3535d786813ea52104d0b0f80778306342342e04d89f4947f`);
   `/root/.config/uprava/alert.env` (chmod 600, вне git) ← токен «Вестника» расшифровкой из прод-`MarketingSetting.tg_bot_token`, чат `-1002079934542`.
   Проба без отправки: `/usr/local/bin/uprava_alert --check` → `CHECK OK bot=samskrte_bot chat=-1002079934542`.
4. Файлы — обычным путём: `bash scripts/server_guards_apply.sh` на .92 и `--profile n8n` на .91 (оба включают таймеры в том же прогоне).

## Приёмка (живая, 23-11-2026)

| Контроль | Действие | Результат |
|---|---|---|
| Норма | проба на живом туннеле | `OK socks=127.0.0.1:1080 code=200` → `WITNESS push ok`; свидетель `GREEN — метка свежая: 35s назад` |
| Положительный | `touch -d "20 minutes ago"` по метке + прогон свидетеля | `FAIL — метка молчит 1200s (>900s)` → `DELIVERED message_id=53356` в «Отдел заботы» |
| Дедуп | второй прогон в том же окне | `страница задедуплена: предыдущая 0s назад (окно 3600s)` — второго сообщения нет |
| Отрицательный (пуш) | проба на мёртвый порт `SOCKS_LIVENESS_PORT=1081`, `NO_HEAL=1` | `FAIL`, `ALARM relay_rc=0` (тот же текст доставлен: 53357), **mtime метки не двинулся** — пуш только на успехе |
| Флап | `systemctl restart socks-nl.service` | рестарт 0 c, проба сразу `code=200`; порог 900 c ≫ рестарта — штатный рестарт страницу не рождает |
| Изоляция ключа | `ssh -i id_socks91_witness socks91-witness@.92 "id; cat /etc/shadow"` | exit 0, **вывода нет**: выполнен `touch`, запрошенные команды не запускались |
| Лечение | — | штатный самохил (`restart socks-nl.service`) сохранён в пробе; отрицательный контроль идёт только с `SOCKS_LIVENESS_NO_HEAL=1` |

Пояснение к двум сообщениям в чате: 53356 и 53357 — управляемые проверки, следом ушло пояснение (53359). Бот «Вестник» пишет receipt-JSONL в `/root/.local/state/uprava/uprava_alert.jsonl` на .92.

## Что НЕ трогалось

- `/etc/privoxy/config` и правило `forward-socks5t` для `.googleapis.com` — п.3 issue #4429 закрыт 23-11-2026 другой сессией (бэкап `/etc/privoxy/config.bak-2026-11-23-pre-googleapis-direct`), смоук-исполнение 16839 зелёное. Здесь ничего не переделывается и не откатывается.
- `socks-nl.service` и его exit node — только рестарт в штатном самохиле.
- `hermes_notify.sh` намеренно не используется: он шлёт лично MG, а рулинг 23-11-2026 требует «Отдел заботы».

## Откат

1. `systemctl disable --now systema-socks91-watch.timer` (.92) — свидетель замолкает, проба .91 продолжает работать как раньше.
2. `systemctl disable --now socks-liveness.timer` (.91) — вернуть .91 к состоянию до #4429 (не рекомендуется).
3. Файлы — `git revert` коммита H8564 и `bash scripts/server_guards_apply.sh`; ключ и пользователь — `userdel -r socks91-witness` (.92), `rm /root/.ssh/id_socks91_witness*` (.91).
4. Метка и состояние: `/var/lib/systema-guards/socks91_hub.last`, `socks91_watch.last_page` — удаляются без последствий (следующий прогон начнёт с «метки нет», то есть со страницы).

## Остатки (в GTD, не в этом PR)

- `.91`-стейджинг `/opt/systema-guards/scripts` отставал от `origin/main` (в нём не было строки `sbin/disk_watch.py`), поэтому `server_guards_n8n_verify.sh` на нём видел 1 critical + 1 warning, а после синхронизации манифеста — 1 critical + 2 warning. Дрейф **не мой**: `n8n-backup-run.sh` (critical), `disk_watch.py`, `systema-canary.service` разошлись с репозиторием до H8564. Их и полную синхронизацию стейджинга надо закрыть отдельной строкой.
- `systema-socks91-watch` в `SENTINELS.md` (строка зарегистрирована, `implemented 44`).
- Сам свидетель никем не читается по той же логике H4929: его штамп `/home/hermes/heartbeats/socks91_watch.stamp` — готовый вход для будущего третьего слоя; сейчас это честно записано, а не выдано за покрытие.

_к.ф.н. М.Ю. Гасунс_
