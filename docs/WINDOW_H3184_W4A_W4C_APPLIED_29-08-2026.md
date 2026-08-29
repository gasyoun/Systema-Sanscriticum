# Окно H3184 W4a/W4c — применено 29-08-2026

_Created: 29-08-2026 · Last updated: 29-08-2026_

Закрытие того самого окна с человеком у пульта, которого волна 4 ждала с
19-08-2026. Исполнено под residual-handoff'ом
[H3661](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3661-Opus_Systema-Sanscriticum_h3184-residual-w4a-w4c-human-window-apply_29.08.26.md),
потому что ID H3184 залочен слитыми PR
[#2138](https://github.com/gasyoun/Systema-Sanscriticum/pull/2138) и
[#2139](https://github.com/gasyoun/Systema-Sanscriticum/pull/2139)
(`precheck_handoff.py` exit 4, овер-райда не существует).

Процедура: [RUNBOOK_H3184_W4A_NFTABLES_DEFAULT_DENY_WINDOW_27-08-2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_H3184_W4A_NFTABLES_DEFAULT_DENY_WINDOW_27-08-2026.md).

## Границы окна

| Что | Значение |
|---|---|
| Машина | `.91` `samskrtam50` (W4a + W4c). `.92` — НЕ трогали: W4b требует 7 чистых суток на `.91` |
| Человек у пульта | MG, весь прогон |
| Исполнитель | Opus 5 (`claude-opus-5`) |
| Начало (первое изменение на машине) | 2026-08-29T06:20Z — установка `at` |
| Конец | 2026-08-29T06:38Z |
| Путь доступа | Tailscale `100.80.27.28`, узел `vps-n8n` |

## Что сделано

1. Установлен `at`, поднят `atd` — без них `systema-nft-armed-apply.sh arm` отказывается работать, а откат и есть единственный предохранитель волны.
2. Откат взведён ПЕРВЫМ: задание `1`, 06:21:04Z, срабатывание 06:31:00Z. Тело задания прочитано глазами: `nft add chain inet filter input "{ … policy accept; }"` — Docker'ские таблицы не трогает.
3. `apply` в 06:21:40Z — `policy drop` с аллоу-листом.
4. Новая SSH-сессия открыта в 06:22:37Z (3 установленных сессии на 22).
5. `verify` в 06:23:04Z — три из трёх: новая сессия, кросс-проба до `.92`, живой Tailscale.
6. Внешние смоуки: `samskrte.ru` 200, `context-ai.ru` 200. Кросс-проба С `.92` на `.91` по приватной сети: `http=200`.
7. `disarm` в 06:24:05Z, `atq` пуст.
8. Ruleset продвинут в `/etc/nftables.conf` (см. инцидент 1 ниже). Резервная копия: `/etc/nftables.conf.pre-h3184-20260829`.
9. W4c: `10-hardening.conf` + jail fail2ban, `sshd -t` до перезагрузки конфига, `systemctl reload ssh`, `systemctl enable --now fail2ban`.

## Приёмка (все пункты закрыты)

| Требование | Факт |
|---|---|
| `input` policy `drop` с аллоу-листом | да, `nft list table inet filter` |
| Новая SSH-сессия открывается | да, многократно после применения |
| `passwordauthentication no` | да |
| `permitrootlogin prohibit-password` | да (`sshd -T` печатает алиас `without-password`) |
| Активный jail sshd | да — `fail2ban-client status sshd`, уже забанен реальный подборщик `45.225.135.20` |
| Публичные поверхности живы | `samskrte.ru` 200, `context-ai.ru` 200 |
| Управляемые файлы в манифесте | 5 строк волны 4 раскомментированы, `server_guards_n8n_verify.sh` → 45 ok |

Оставшийся `critical` в проверке — `backup-offsite`, 88 ч вместо порога 30 ч. К этой волне отношения не имеет, это предмет
[H3413](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3413-Sonnet_Systema-Sanscriticum_s3-offsite-activate_24.08.26.md).

## Инцидент 1 — `systemctl restart nftables` снёс таблицы Docker

**Стоило ~3 минут простоя `context-ai.ru` (06:28:35Z–06:31:40Z).**

Чтобы проверить загрузочный путь, был выполнен `systemctl restart nftables`.
Юнит `nftables.service` в Debian выполняет `nft flush ruleset` на ОСТАНОВКЕ —
это снесло не только нашу `table inet filter`, но и `table ip nat` /
`table ip filter`, которые держит Docker через `iptables-nft`. Результат:
`iptables -t nat -S` без единого `MASQUERADE`, исходящий трафик контейнеров
мёртв (`EGRESS_TIMEOUT` из n8n).

**Ловушка внутри ловушки:** сайт при этом ОТВЕЧАЛ 200. Caddy опубликован через
`docker-proxy` в userspace, трафик приходит на `INPUT`, где наш аллоу-лист его
пропускает. Поломка не видна снаружи вообще — только изнутри контейнера.
Мониторинг по внешнему HTTP такую аварию не поймает.

Вывод, записанный в шапку
[systema-default-deny.nft](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards_n8n/nftables/systema-default-deny.nft):
ruleset применяется `nft -f <файл>`, а рестартом юнита — никогда.
На загрузке это безопасно (Docker стартует позже и пересоздаёт своё).

## Инцидент 2 — `_comment` в `daemon.json` не давал Docker'у стартовать

Восстановление по инциденту 1 (`systemctl restart docker`) провалилось:

```
unable to configure the Docker daemon with file /etc/docker/daemon.json:
the following directives don't match any configuration option: _comment
```

`dockerd` не игнорирует неизвестные ключи, а отказывается стартовать. Файл
приехал на машину 25-08-2026 в составе волны 3 и с тех пор лежал МИНОЙ: демон
продолжал работать со старым конфигом в памяти, поэтому поломка не проявлялась.
**Любой рестарт Docker'а или перезагрузка машины уронили бы `.91` независимо от
этой волны** — окно волны 4 её просто подорвало раньше, при человеке у пульта.

Исправлено в шаблоне репозитория (ключ убран, пояснение перенесено в комментарии
[manifest.psv](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards_n8n/manifest.psv))
и на машине. Резервная копия сломанного файла: `/etc/docker/daemon.json.broken-comment-20260829`.

## Что осталось

- **W4b (`.92`)** — только после 7 чистых суток на `.91`, то есть не раньше 05-09-2026.
- **`ignoreip` для fail2ban не задан** ни на одной машине (паритет с `.92` сохранён намеренно). На машине без консоли бан по приватной сети или тайлнету отрезал бы ровно те пути восстановления, которые аллоу-лист бережёт. Вход только по ключу, `bantime` дефолтный, так что риск низкий — но решение о `ignoreip` стоит принять отдельно, а не молча внутри этой волны.
- **Проверить `.91` перезагрузкой** — загрузочный путь через `include /etc/nftables.d/*.nft` синтаксически проверен (`nft -c -f`), но настоящей перезагрузки в этом окне не было.

_Dr. Mārcis Gasūns_
