_Created: 30-07-2026 · Last updated: 05-09-2026_

# Если сайт не открывается (Better Stack + Telegram)

_Создано: 30-07-2026 · Обновлено: 14-08-2026_

Три аудитории — **не смешивать**:

| Кто | Что делать | Ссылка |
|---|---|---|
| **Ученик / преподаватель** | 3 проверки → написать в чат с `@rusamskrtam` | [ниже §1](#1-ученики-и-преподаватели--1-минута) · живая страница [samskrte.ru/uptime](https://samskrte.ru/uptime) |
| **Куратор в «Отделе заботы»** | Реплай / `@grokusaurus_bot` / `@rusamskrtam` | [MANUAL_CURATOR_GROK_ZABOTA_BOT_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MANUAL_CURATOR_GROK_ZABOTA_BOT_RU.md) |
| **Иван / Марцис** | Смотреть, *что* красное; чинить или звать агента / Артёма | [§2](#2-иван-и-марцис--ops) |
| **Агенты / SSH** | Env, cron, smoke | [EN inventory](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING.md) |

**Артёму (@t3t3r1n) пишет только Иван или Марцис** — не ученики.

---

## 1. Ученики и преподаватели — 1 минута

### Сейчас сделайте 3 шага

**1. Откройте эти ссылки по очереди** (лучше сначала с **телефона** на мобильном интернете, не Wi‑Fi):

| Что открыть | Ссылка |
|---|---|
| Главная школы | [samskrte.ru](https://samskrte.ru/) |
| Вход в кабинет | [samskrte.ru/login](https://samskrte.ru/login) |
| Страница «сайт жив?» | [samskrte.ru/uptime](https://samskrte.ru/uptime) |
| Запасная страница (если samskrte лежит) | [gasyoun.github.io/…/uptime/](https://gasyoun.github.io/Systema-Sanscriticum/uptime/) |
| Книги / parallel-corpus | [samskrtam.ru](https://samskrtam.ru/) · [parallel-corpus](https://samskrtam.ru/parallel-corpus/) |

**2. Сравните результат**

| Что видите | Значит | Что делать |
|---|---|---|
| На **телефоне** открывается, на **компе** — нет | Чаще Wi‑Fi / **VPN** / антивирус / DNS у вас | Выключите VPN на 1 мин, другой браузер, мобильный интернет |
| **Нигде** не открывается (телефон + комп + другой браузер) | Скорее **наш сайт** | Пишите в чат — шаг 3 |
| Главная есть, **/login** или кабинет нет | Частичная поломка | Пишите в чат — шаг 3 |
| Всё открывается, но «медленно / 502» | Может быть сбой | Тоже можно написать в чат |

**3. Напишите в Telegram-чат** и **отметьте** [@rusamskrtam](https://t.me/rusamskrtam)

Скопируйте шаблон:

```text
@rusamskrtam сайт не открывается
где: телефон / комп
VPN: вкл / выкл
что пробовал: samskrte.ru · /login · /uptime
что вижу: (белый экран / ошибка / крутится / другое)
время: (сейчас)
```

Это увидят **Иван и Марцис**. Кто первый — запустит Claude/Grok или починит на сервере.  
**Не пишите Артёму** и не ищите «красный монитор» в Better Stack — это не ваша зона.

Кураторы **в штабном** чате «Отдел заботы | Рабочая группа» могут сразу звать
[@grokusaurus_bot](https://t.me/grokusaurus_bot) (реплай надёжнее голого `@`).
Как писать и сколько ждать: [MANUAL_CURATOR_GROK_ZABOTA_BOT_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MANUAL_CURATOR_GROK_ZABOTA_BOT_RU.md).

### Как узнать о падении *до* того, как «опять не пускает»

1. Закладка: [samskrte.ru/uptime](https://samskrte.ru/uptime) — откройте, если сомневаетесь.  
2. Запасная закладка (работает, когда VPS школы лежит):  
   [github.io …/uptime/](https://gasyoun.github.io/Systema-Sanscriticum/uptime/)  
3. Сообщение в общем чате с `@rusamskrtam` — так остальные узнают, что это не «только у меня».  
4. Письма Better Stack и служебные алерты — **для ops** (Иван/Марцис), не для учеников.

### Преподаватели

То же, что ученики. Если у **всей группы** не открывается — напишите в чат `@rusamskrtam` и кратко «у N человек».

**Шпаргалка + текст для закрепа в TG:**  
[TEACHER_SITE_DOWN_CHEATSHEET_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/TEACHER_SITE_DOWN_CHEATSHEET_RU.md) ·  
[marketing/teacher-site-down-telegram-pin.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/teacher-site-down-telegram-pin.md)  
(авто-алерт «сайт упал» преподавателям **не** приходит — только чат + `@rusamskrtam`).  
**Форвард soft/ops → преподам = NO-GO** (census 06-08: 0 critical / 107 soft):  
[CENSUS_CABINET_PROBE_SOFT_VS_CRITICAL_2026-08-06.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CENSUS_CABINET_PROBE_SOFT_VS_CRITICAL_2026-08-06.md).

---

## 2. Иван и Марцис — ops

### Кто кого зовёт

| Ситуация | Кто |
|---|---|
| Ученик написал `@rusamskrtam` | Кто увидел первый (Иван или Марцис) → агент (Claude/Grok) или SSH |
| Сайт мёртв, SSH нет, «машина мертва» | **Иван или Марцис → Артём (@t3t3r1n)** (Proxmox/контейнер). Ученики к Артёму **не** пишут |
| Soft-сбой / guards | Разобрать по тексту; не эскалировать Артёму |

### Посмотрите, *что* красное

Панель (нужен логин): [Better Stack team](https://uptime.betterstack.com/team/t576984)

| Красный сигнал | Проверить глазами | О чём это |
|---|---|---|
| HTTP **samskrte.ru** | [samskrte.ru](https://samskrte.ru/) | Школа снаружи не 200 |
| Heartbeat **heartbeat:ping** | [EN §2.1](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING.md) | Планировщик/сервер молчит |
| Heartbeat **cabinet:probe** | [samskrte.ru/login](https://samskrte.ru/login) + probe | Кабинет / guards |
| HTTP / heartbeat **samskrtam** | [samskrtam.ru](https://samskrtam.ru/) · [parallel-corpus](https://samskrtam.ru/parallel-corpus/) | **Другой** сервер, не Systema VPS |
| HTTP / heartbeat **Cologne / CDSL** | [sanskrit-lexicon.uni-koeln.de](https://sanskrit-lexicon.uni-koeln.de/) | Не наш; в [SERVER_OUTAGES](https://github.com/gasyoun/Uprava/blob/main/SERVER_OUTAGES.md) |

Telegram кабинета:

| Текст | Смысл |
|---|---|
| «Личный кабинет не работает» | Критично |
| «soft-сбой» | Некритично; сайт может быть жив |

Команды (root, кратко):

```text
ssh root@193.232.229.92
systemctl status php8.3-fpm nginx
df -h /
cd /var/www/html && sudo -u www-data php artisan cabinet:probe
sudo -u www-data php artisan heartbeat:ping
```

Полный inventory: [UPTIME_BETTERSTACK_MONITORING.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING.md).

---

## 3. Три места «жив ли сайт»

| Где | Когда работает | URL |
|---|---|---|
| **samskrte.ru/uptime** | Когда VPS школы жив | [samskrte.ru/uptime](https://samskrte.ru/uptime) |
| **GitHub Pages (зеркало)** | Когда VPS **лежит**, а интернет у вас есть | [gasyoun.github.io/Systema-Sanscriticum/uptime/](https://gasyoun.github.io/Systema-Sanscriticum/uptime/) |
| **samskrtam.ru/uptime** | На хостинге книг (отдельный сервер) | [samskrtam.ru/uptime](https://samskrtam.ru/uptime) — страница-близнец; если 404, поставить HTML из `uptime/samskrtam-snippet.html` в репо |

Хотя бы **одно** из первых двух должно открываться при типичном сбое.

---

## 4. Что мониторим (кратко)

| Сайт | Чей сервер |
|---|---|
| samskrte.ru | наш (Systema) |
| samskrtam.ru | другой (WordPress + статика) |
| sanskrit-lexicon.uni-koeln.de | Кёльн |

HTTP = «мир видит страницу». Heartbeat = «наш cron/сервер отписался».

Токены только на сервере, не в GitHub. healthchecks.io — obsolete.

---

_Dr. Mārcis Gasūns_
