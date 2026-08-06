# Шпаргалка преподавателю: сайт или ДЗ «не работают»

_Created: 06-08-2026 · Last updated: 06-08-2026 (census: no teacher forward)_

**Кому:** преподаватели (Костина, Литвиненко, …) и кураторы в учебных чатах.  
**Не для ops:** красные мониторы Better Stack — только Иван / Марцис  
([полная инструкция](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING_RU.md)).

**Главное одной фразой:** вам **не** приходит автоматический алерт «сайт упал».  
Если не открывается кабинет или **не сдаётся домашнее** — проверьте → напишите в чат с **`@rusamskrtam`**.

**Почему нет форварда ops-алертов:** сейчас в мониторинге кабинета почти всё —  
**soft** (auto-deploy / dirty, сайт часто жив), а не «кабинет умер».  
Замер 06-08-2026: 0 critical / 107 soft-only в истории probe  
([census](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CENSUS_CABINET_PROBE_SOFT_VS_CRITICAL_2026-08-06.md)).  
Пока soft-шум не усмирят, авто-пинги преподам **не** включают.

Живая страница для учеников и вас: [samskrte.ru/uptime](https://samskrte.ru/uptime)  
(зеркало, если VPS лежит: [GitHub Pages](https://gasyoun.github.io/Systema-Sanscriticum/uptime/)).

Текст для **закрепа** в чате преподавателей / штаба:  
[marketing/teacher-site-down-telegram-pin.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/teacher-site-down-telegram-pin.md).

---

## 1. Как вы узнаёте о поломке

| Канал | Что происходит |
|---|---|
| **Вы сами** | Не открывается сайт, вход, кабинет; ДЗ не отправляется / ошибка |
| **Ученики** | «Не могу сдать», «белый экран», «крутится» — в чате группы |
| **Закреп / uptime** | [samskrte.ru/uptime](https://samskrte.ru/uptime) — три шага + шаблон |
| **Авто-алерт ops** | Better Stack, TG «кабинет не работает» — **Иван / Марцис**, не преподам |

Отдельного пуша «ДЗ сломано → всем преподам» **нет**. Узкий сбой сдачи (форма, файл, один урок) ops видит, когда кто-то написал `@rusamskrtam`.

---

## 2. Что сделать за 1 минуту

**Шаг 1.** Откройте (лучше с **телефона** на мобильном интернете, не Wi‑Fi):

| Ссылка | Зачем |
|---|---|
| [samskrte.ru](https://samskrte.ru/) | Жива ли главная |
| [samskrte.ru/login](https://samskrte.ru/login) | Жив ли вход |
| [samskrte.ru/uptime](https://samskrte.ru/uptime) | Подсказка «VPN vs сайт» |
| [зеркало](https://gasyoun.github.io/Systema-Sanscriticum/uptime/) | Если сам samskrte не грузится |

**Шаг 2.** Сравните:

| Что видите | Значит | Действие |
|---|---|---|
| На телефоне есть, на компе нет | Часто VPN / Wi‑Fi / DNS у вас | Выключите VPN, другой браузер |
| Нигде нет (телефон + комп) | Скорее наш сайт | Шаг 3 |
| Главная есть, кабинет / сдача ДЗ нет | Частичная поломка | Шаг 3 |
| У **всей группы** то же | Системно | Шаг 3 + «у N человек» |

**Шаг 3.** В Telegram-чате школы **отметьте** [@rusamskrtam](https://t.me/rusamskrtam)  
(увидят **Иван и Марцис**). **Не пишите Артёму** — его зовут только ops, если мёртв сервер.

### Шаблон (скопировать)

```text
@rusamskrtam сайт / ДЗ не работают
где: телефон / комп
VPN: вкл / выкл
что: (не открывается / не сдаётся ДЗ / ошибка при файле)
урок / курс: (если знаете)
сколько человек: (1 / вся группа N)
что вижу: (белый экран / 502 / крутится / текст ошибки)
время: сейчас
```

---

## 3. ДЗ: «сломалось» vs «ещё не открыто»

Полная инструкция для учеников: [samskrte.ru/faq/dz](https://samskrte.ru/faq/dz) ·  
[STUDENT_HOMEWORK_GUIDE_RU](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_HOMEWORK_GUIDE_RU.md).

| Симптом | Часто значит | Кому писать |
|---|---|---|
| У одного ученика «нет формы» / «задание ещё не задано» | Приём к уроку **не открыт** или ДЗ не задано | Сначала вы / куратор по курсу; не обязательно `@rusamskrtam` |
| У **многих** 5xx, таймаут, «не отправляется», сайт лежит | Поломка платформы | **`@rusamskrtam`** |
| Главная жива, а сдача падает с ошибкой | Частичный сбой | **`@rusamskrtam`** + скрин / текст ошибки |

Пока чинят: можно принять файлы **в чат группы** как запасной путь и перенести в кабинет позже — по вашему решению.

---

## 4. Кого не звать

| Кому | Когда |
|---|---|
| **`@rusamskrtam`** | Сайт / кабинет / массовая сдача ДЗ |
| **Артём** | Только через Ивана или Марциса (инфраструктура) |
| Better Stack / «красный монитор» | Не ваша зона |

---

## 5. Связанные ссылки

| Документ | Для кого |
|---|---|
| [samskrte.ru/uptime](https://samskrte.ru/uptime) | Ученики + преподаватели |
| [UPTIME_BETTERSTACK_MONITORING_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING_RU.md) | §1 люди · §2 ops |
| [teacher-site-down-telegram-pin.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/teacher-site-down-telegram-pin.md) | Закреп в TG |
| [STUDENT_HOMEWORK_GUIDE_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_HOMEWORK_GUIDE_RU.md) | Как сдавать ДЗ, когда сайт жив |

Grok 4.5 (`grok-4.5`) · H2325.

_Dr. Mārcis Gasūns_
