# Runbook — окно H3184 W4a/W4c: default-deny nftables и укрепление SSH на `.91`

_Created: 27-08-2026 · Last updated: 27-08-2026_

Инструкция на то самое окно с человеком у пульта, ради которого волна 4 ждёт с
19-08-2026. Всё, что можно было сделать заранее, сделано заранее — здесь остались
только шаги, которые нельзя выполнять без человека.

Handoff: [H3184](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3184-Opus_Systema-Sanscriticum_uptime-w4-nftables-default-deny-ssh-hardening_19.08.26.md) ·
план: [PLAN_SYSTEMA_SERVER_UPTIME_GUARDRAILS_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_SERVER_UPTIME_GUARDRAILS_2026H2.md) ·
проверки: [VERIFICATION_SYSTEMA_SERVER_UPTIME_GUARDRAILS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_SERVER_UPTIME_GUARDRAILS.md)

## Поправка к модели риска handoff'а: второй путь ЕСТЬ

H3184 написан на посылке «recovery requires Artem and the Proxmox host. There is
no second path». Замерено 27-08-2026 — посылка неверна, и это меняет цену волны.

На `.91` поднят Tailscale: узел `vps-n8n`, адрес `100.80.27.28`, интерфейс
`tailscale0`. В той же сети — `.92` (`vps-samskrte92`), рабочая машина
(`win-njtorh3267v`) и выходной узел `vps-exit`. Проверено живым входом:

```
ssh root@100.80.27.28   →   samskrtam50
```

Отпечаток ключа совпадает с публичным адресом (`SHA256:D6OQDIMwEUMBa2kKVPaUYPF7aGu1/lCG7btLnwJEAMA`),
то есть это та же машина, а не похожая.

Почему этот путь переживает default-deny: туннель поднимается ИСХОДЯЩИМ
соединением, а при закрытом входящем UDP откатывается на DERP-реле (ближайшее —
Helsinki, 10.3 мс), которому нужен только исходящий 443. Волна меняет политику
`input`; `output` остаётся `accept`.

**Ловушка, ради которой это написано.** Запасной путь переживает default-deny
только если аллоу-лист пропускает `tailscale0`. Туннель останется поднятым, а
расшифрованный SSH-пакет, вышедший на `tailscale0`, упрётся в `policy drop` — и
машина потеряет ОБА пути сразу, выглядя при этом корректно настроенной. Правило
`iifname "tailscale0" accept` в
[systema-default-deny.nft](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards_n8n/nftables/systema-default-deny.nft)
— не удобство, а второй предохранитель.

Артём как путь восстановления остаётся, но он в отпуске примерно до 19-09-2026,
так что до его возвращения Tailscale — единственный реальный запасной путь, и
проверять его надо ДО применения, а не после.

## Состояние `.91` на 27-08-2026 (замерено, не предположено)

| Что | Значение |
|---|---|
| `table inet filter` policy input | `accept` — не фильтруется ничего |
| `/etc/ssh/sshd_config.d/` | пуст |
| `sshd -T passwordauthentication` | `yes` — подбор пароля открыт |
| `sshd -T permitrootlogin` | `without-password` |
| `fail2ban` | `inactive` |
| Tailscale | активен, `100.80.27.28`, UDP `41641` |
| Слушают наружу | `22` (sshd), `80`/`443` (docker-proxy → Caddy) |
| Только на петле | n8n `5678`, MariaDB `3306`, postfix `25`, privoxy `8118` |
| eth0 | `192.168.200.91/24` — публичного адреса на интерфейсе нет, NAT со стороны Proxmox |
| `systema-peer-probe.timer` | активен, живой такт |
| авто-деплой | ОТСУТСТВУЕТ: `systema-auto-deploy-run.sh` нет, таймер inactive |

Последняя строка — причина, по которой правила можно было подготовить заранее:
слияние PR ничего на `.91` не применяет. [deploy.yml](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.github/workflows/deploy.yml)
запускается только вручную (`workflow_dispatch`) и за гейтом Environment
`production` с ревьюером MG.

## Аллоу-лист выведен из живого трафика

Требование handoff'а «Allowlist must be derived, not assumed» закрыто замерами
выше: `ss -tlnp`, `ss -ulnp`, `nft list ruleset`, `tailscale status`,
`ip -brief addr`, перепись установленных соединений.

Два решения, которые легко сделать неправильно:

1. **`forward` остаётся `accept`.** Опубликованные Docker'ом 80/443 приходят
   через DNAT в `PREROUTING` и идут по `FORWARD`, а не по `INPUT`. `forward drop`
   снял бы сайт с интернета при идеально выглядящем input-аллоулисте.
2. **Порт 22 не закрывается.** Волна делает вход только по ключу и вешает
   fail2ban; закрытие порта было бы вторым lockout-риском без выигрыша.

Проверено на самой машине: `nft -c -f` (dry-run) на nftables v1.1.3 —
`SYNTAX_OK`, политика после проверки осталась `accept`.

## Порядок в окне

Сначала `.91` целиком (W4a, затем W4c). `.92` (W4b) — только после семи чистых
суток на `.91`; окно, которое планирует обе машины сразу, размечено против
собственного правила очерёдности handoff'а.

1. Убедиться, что Tailscale-путь жив: с рабочей машины `ssh root@100.80.27.28`.
   Не прошло — окно отменяется, запасного пути нет.
2. Разложить файлы: `bash scripts/server_guards_apply.sh --profile n8n`
   (после того как строки волны 4 в
   [manifest.psv](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards_n8n/manifest.psv)
   раскомментированы).
3. Взвести откат ПЕРВЫМ: `systema-nft-armed-apply.sh arm 10`. Прочитать глазами
   вывод `atq` и тело задания.
4. Применить: `systema-nft-armed-apply.sh apply`. Скрипт откажется работать без
   взведённого отката.
5. Открыть **новую** SSH-сессию с другой машины. Текущая ничего не доказывает:
   установленные соединения переживают правила, режущие новые.
6. `systema-nft-armed-apply.sh verify` — новая сессия, кросс-проба до `.92`,
   живой Tailscale.
7. Только после чистого verify: `systema-nft-armed-apply.sh disarm`.
8. Продвинуть ruleset в `/etc/nftables.conf`, чтобы он пережил перезагрузку.
9. W4c: положить `10-hardening.conf` и jail fail2ban, `systemctl reload ssh`,
   `systemctl enable --now fail2ban`, проверить `sshd -T` и
   `fail2ban-client status` — снова НОВОЙ сессией.

При любой неоднозначности — не снимать откат. Слитый ruleset восстанавливается;
lockout на машине без консоли — нет.

## Что закрывает окно

- `nft list ruleset` с обеих машин
- `atq` и тело задания с отметкой времени ДО применения
- транскрипт новой SSH-сессии
- подтверждение кросс-пробы с соседа
- `sshd -T` и `fail2ban-client status` с `.91`
- имя человека, который был у пульта, и границы окна

_Dr. Mārcis Gasūns_
