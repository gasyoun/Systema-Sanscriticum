# Мониторинг сайтов (Better Stack) — для людей

_Создано: 30-07-2026 · Обновлено: 30-07-2026_

**Для кого:** вы, если пришло письмо/Telegram «сайт красный» или нужно понять, что мы вообще следим.  
**Не для агентов:** полная техническая карта (env, cron, скрипты) — в английской версии для агентов:  
[UPTIME_BETTERSTACK_MONITORING.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING.md).

Панель: [Better Stack — team](https://uptime.betterstack.com/team/t576984)  
(нужен логин в Better Stack).

---

## Что мы мониторим

| Сайт | Чей сервер | Что смотрим |
|---|---|---|
| **samskrte.ru** | наш (Systema) | главная; «жив ли планировщик»; «жив ли личный кабинет» |
| **samskrtam.ru** | другой (WordPress + статика) | главная; страница parallel-corpus; плюс проверка «с нашего VPS видно» |
| **sanskrit-lexicon.uni-koeln.de** | Кёльн (не наш) | главная словарей; плюс проверка с нашего VPS |

Два типа проверки:

1. **HTTP** — Better Stack сам открывает страницу из интернета.  
2. **Heartbeat (пульс)** — наш сервер раз в N минут «отписывается». Если отписка пропала — тревога по **молчанию**.

---

## Если что-то «упало» — что делать вам

### 1. Посмотрите, *что* красное

В [Better Stack → Monitors / Heartbeats](https://uptime.betterstack.com/team/t576984):

| Красный сигнал | О чём это |
|---|---|
| HTTP **samskrte.ru** | Сайт школы снаружи не открывается или отвечает «не 200» |
| Heartbeat **heartbeat:ping** | Планировщик/сервер не шлёт пульс (часто хуже, чем «просто главная») |
| Heartbeat **cabinet:probe** | Проверка кабинета не проходит или не запускается |
| HTTP / heartbeat **samskrtam** | Другой сайт (книги/корпус) — **не** тот же сервер, что samskrte |
| HTTP / heartbeat **Cologne / CDSL** | Словари Кёльна; **мы не можем перезагрузить их сервер** |

В Telegram от кабинета:

| Текст | Смысл |
|---|---|
| «Личный кабинет не работает» | Критично: вход/кабинет/админка |
| «soft-сбой» | Некритично (второстепенные страницы/guards); сайт может быть жив |

### 2. Быстрая самопроверка

- Откройте в браузере (лучше с телефона / другой сети):  
  - https://samskrte.ru/  
  - https://samskrte.ru/login  
  - при нужде: https://samskrtam.ru/ и https://samskrtam.ru/parallel-corpus/  
  - при нужде: https://sanskrit-lexicon.uni-koeln.de/  
- Если **у вас открывается**, а Better Stack красный — подождите 5–15 минут (сеть/ложный сбой) или напишите, *какой именно* монитор красный.

### 3. samskrte.ru (наш сервер) — кто что делает

| Ситуация | Кто |
|---|---|
| Сайт совсем не открывается, SSH недоступен, «машина мертва» | **Артём (@t3t3r1n)** — рестарт контейнера/хоста (Proxmox). Отвечает нечасто |
| Сайт лежит, но есть человек с root на VPS | По инструкции в agent-доке §5.2: `php-fpm`, nginx, диск, `cabinet:probe` |
| Кабинет/логин, а главная жива | Смотреть Telegram-текст алерта + agent-док §5.2; не обязательно ждать Артёма |
| Только soft-сбой | Не паника; разобрать по тексту (часто не «весь сайт») |

Команды для того, у кого есть SSH (кратко):

```text
ssh root@193.232.229.92
systemctl status php8.3-fpm nginx
df -h /
cd /var/www/html && sudo -u www-data php artisan cabinet:probe
sudo -u www-data php artisan heartbeat:ping
```

### 4. samskrtam.ru

Это **не** Systema VPS. Лечится на хостинге WordPress/статики (панель хостера, nginx, диск).  
С нашего сервера только *проверка*, не «перезапуск WordPress».

### 5. Словари Кёльна

Мы **не** владельцы. Можно:

- зафиксировать, что лежит (для агентов — [SERVER_OUTAGES](https://github.com/gasyoun/Uprava/blob/main/SERVER_OUTAGES.md));  
- подождать / написать Кёльну;  
- не гонять массовые выгрузки, пока host красный.

---

## Частые вопросы

**Нужно ли ставить плагин на WordPress?**  
Нет. Better Stack ходит снаружи.

**Почему два алерта на один сайт?**  
HTTP = «мир видит страницу». Heartbeat = «наш сервер/cron жив и проверка прошла». Разные поломки.

**Где пароли и токены?**  
Только на сервере (`.env`, `/etc/default/*`). В GitHub их нет — так и должно быть.

**healthchecks.io?**  
Больше не используем. Всё на Better Stack.

**Где подробности для технарей/агентов?**  
[UPTIME_BETTERSTACK_MONITORING.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING.md) (English, for agents).

---

_Dr. Mārcis Gasūns_
