# Мониторинг сайтов (Better Stack) — если сайт упал

_Создано: 30-07-2026 · Обновлено: 30-07-2026_

**Для кого:** сайт **перестал работать**, или пришло письмо / сообщение, что
сайт **красный** (в т.ч. в Telegram [@rusamskrtam](https://t.me/rusamskrtam)).

**Не для агентов:** env, cron, скрипты и inventory — в английской версии:  
[UPTIME_BETTERSTACK_MONITORING.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING.md).

Панель Better Stack (если есть логин):  
[uptime.betterstack.com/team/t576984](https://uptime.betterstack.com/team/t576984)

---

## Сначала: что сделать прямо сейчас

1. **Откройте сайт сами** (лучше с телефона / другой сети, не с того же Wi‑Fi):
   - [samskrte.ru](https://samskrte.ru/) — школа / кабинет  
   - [samskrte.ru/login](https://samskrte.ru/login) — вход  
   - при нужде: [samskrtam.ru](https://samskrtam.ru/), [parallel-corpus](https://samskrtam.ru/parallel-corpus/)  
2. **Посмотрите, *какой* сайт в алерте.**  
   samskrte.ru, samskrtam.ru и словари Кёльна — **разные** сервера; «красный
   Кёльн» не значит, что школа лежит.
3. **Если у вас открывается, а «красный» в письме/Telegram** — подождите
   5–15 минут (сеть, ложный сбой) или сохраните *какой именно* монитор/текст
   алерта, прежде чем звать людей.
4. **Если не открывается** — по таблице ниже: кого звать и что это значит.

---

## Что мы мониторим

| Сайт | Чей сервер | Что смотрим |
|---|---|---|
| **samskrte.ru** | наш (Systema) | главная; «жив ли планировщик»; «жив ли личный кабинет» |
| **samskrtam.ru** | другой (WordPress + статика) | главная; parallel-corpus; плюс проверка «с нашего VPS видно» |
| **sanskrit-lexicon.uni-koeln.de** | Кёльн (не наш) | главная словарей; плюс проверка с нашего VPS |

Два типа проверки:

1. **HTTP** — Better Stack сам открывает страницу из интернета.  
2. **Heartbeat (пульс)** — наш сервер раз в N минут «отписывается». Если
   отписка пропала — тревога по **молчанию** (сервер/cron мог умереть, даже
   если главная ещё отвечает).

Алерты могут приходить **письмом** (Better Stack) и/или в **Telegram**
(в т.ч. канал/чат [@rusamskrtam](https://t.me/rusamskrtam) и служебные
сообщения кабинета вроде «Личный кабинет не работает»).

---

## Если что-то красное — расшифровка

В [Better Stack → Monitors / Heartbeats](https://uptime.betterstack.com/team/t576984)
или в тексте письма/Telegram:

| Красный сигнал | О чём это |
|---|---|
| HTTP **samskrte.ru** | Сайт школы снаружи не открывается или отвечает «не 200» |
| Heartbeat **heartbeat:ping** | Планировщик/сервер не шлёт пульс (часто хуже, чем «просто главная») |
| Heartbeat **cabinet:probe** | Проверка кабинета не проходит или не запускается |
| HTTP / heartbeat **samskrtam** | Другой сайт (книги/корпус) — **не** тот же сервер, что samskrte |
| HTTP / heartbeat **Cologne / CDSL** | Словари Кёльна; **мы не можем перезагрузить их сервер** |

Telegram от кабинета (не всегда Better Stack):

| Текст | Смысл |
|---|---|
| «Личный кабинет не работает» | Критично: вход / кабинет / админка |
| «soft-сбой» | Некритично (второстепенные страницы/guards); сайт может быть жив |

---

## samskrte.ru (наш сервер) — кто что делает

| Ситуация | Кто / что |
|---|---|
| Сайт совсем не открывается, SSH недоступен, «машина мертва» | **Артём (@t3t3r1n)** — рестарт контейнера/хоста (Proxmox). Отвечает нечасто |
| Сайт лежит, но есть человек с root на VPS | Agent-док §5.2: `php-fpm`, nginx, диск, `cabinet:probe` |
| Кабинет/логин, а главная жива | Текст алерта + agent-док §5.2; не обязательно ждать Артёма |
| Только soft-сбой | Не паника; разобрать по тексту (часто не «весь сайт») |

Команды для того, у кого есть SSH (кратко):

```text
ssh root@193.232.229.92
systemctl status php8.3-fpm nginx
df -h /
cd /var/www/html && sudo -u www-data php artisan cabinet:probe
sudo -u www-data php artisan heartbeat:ping
```

---

## samskrtam.ru

Это **не** Systema VPS. Лечится на хостинге WordPress/статики (панель хостера,
nginx, диск). С нашего сервера только *проверка*, не «перезапуск WordPress».

---

## Словари Кёльна

Мы **не** владельцы. Можно:

- зафиксировать, что лежит (для агентов — [SERVER_OUTAGES](https://github.com/gasyoun/Uprava/blob/main/SERVER_OUTAGES.md));  
- подождать / написать Кёльну;  
- не гонять массовые выгрузки, пока host красный.

---

## Частые вопросы

**Пришло в [@rusamskrtam](https://t.me/rusamskrtam) / письмо — это точно падение?**  
Часто да, но сначала откройте URL сами. Бывают ложные/короткие сбои и
путаница «какой сайт».

**Нужно ли ставить плагин на WordPress?**  
Нет. Better Stack ходит снаружи.

**Почему два алерта на один сайт?**  
HTTP = «мир видит страницу». Heartbeat = «наш сервер/cron жив и проверка
прошла». Разные поломки.

**Где пароли и токены?**  
Только на сервере (`.env`, `/etc/default/*`). В GitHub их нет — так и должно быть.

**healthchecks.io?**  
Больше не используем. Всё на Better Stack.

**Где подробности для технарей/агентов?**  
[UPTIME_BETTERSTACK_MONITORING.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING.md) (English, for agents).

---

_Dr. Mārcis Gasūns_
