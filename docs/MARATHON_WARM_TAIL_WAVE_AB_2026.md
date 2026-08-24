# Тёплый хвост марафона — последовательные волны A/B (H3330)

_Created: 22-08-2026 · Last updated: 22-08-2026_

Исполнитель: OxAlpha (`x-preview-f-free`). Решение MG 22-08-2026:
[MONETIZATION_PLAN_2026H2 §3](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MONETIZATION_PLAN_2026H2.md) —
оба оффера тёплого хвоста идут **последовательными волнами**: волна 1 — флагманский купон ₽1 000
(существующая серия, не тронута), волна 2 — членский оффер (Basic ₽1 000 / Club ₽2 000 как «свой темп» вход).
Хендофф: [H3330-OxAlpha_Systema-Sanscriticum_marathon-warmtail-wave-ab-membership-offer_22.08.26.md](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3330-OxAlpha_Systema-Sanscriticum_marathon-warmtail-wave-ab-membership-offer_22.08.26.md).

## 1. Правило назначения волны (детерминированное)

| Волна | Кому | Контент |
|---|---|---|
| 1 `flagship` | Энролы, начавшие **до** даты среза | `config('marathon.warm_tail_messages')` — купон ₽1 000, без изменений |
| 2 `membership` | Энролы, начавшие **в/после** даты среза | `config('marathon.warm_tail_messages_wave2')` — членство |

- Якорь — персональные часы регистрации `marathon_enrollments.day0_started_at`
  (`MarathonEnrollment::warmTailWave()`), не момент отправки.
- Срез — `config('marathon.warm_tail_wave2_from')`, env **`MARATHON_WARM_TAIL_WAVE2_FROM`** (`YYYY-MM-DD`).
  **Пусто/не задано = все на волне 1**. Решение MG 23-08-2026: выставлено **`MARATHON_WARM_TAIL_WAVE2_FROM=2026-09-05`** — волна 2 достаётся запускам с 5 сентября.
- Переключение: выставить env → `php artisan config:cache`. Уже идущие хвосты **не перекрашиваются** —
  волна зафиксирована стартом энрола; срез маршрутизирует только новых.

## 2. Стейджинг wave-2 контента

13 сообщений (ключи 1..13 = Дни 4–16 персональных часов) лежат в
[`config/marathon.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/marathon.php)
→ `warm_tail_messages_wave2`. Плейсхолдеры: `{host}`, `{testimonial}` — общие с волной 1;
`{basic_price}`, `{club_price}`, `{klub_url}` — из `membership_basic_month_price` /
`membership_club_month_price` / `membership_klub_url`. Цены в конфиге — только показ; чекаут читает
реальные тарифы ([контракт трёх тиров](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MEMBERSHIP_THREE_TIER_RECORDING_GATE_2026.md)).

Дуга серии зеркалит волну 1 (темп/возражения → преподаватель → оффер-день 12 → мягкое завершение),
пивот: архив записей + периоды оплаты вместо купона на курс. Идемпотентность доставки не изменена
(`warm_tail_last_day_sent`, H2701 поведение сохранено).

## 3. Измерение

```
php artisan marathon:warmtail-ab-report
```

Таблица по волнам: participants · tripwire · day1 · day2 · purchasers · revenue · rev/participant.
- Покупатель = ≥1 реального выруччного платежа (paid, non-conditional, тариф вне
  `Расход/salary_payout/deposit/trial/marathon_paid`) в окне **day0 .. day0+16 дней**
  (3 дня марафона + 13 дней медианы до оплаты).
- Трипваер считается отдельно колонкой `tripwire` (`paid_at`), в purchasers не входит.
- Нули до запуска — норма (measurement-first). Волны последовательные → вывод «направление,
  не чистый эффект» (примесь календаря); команды отчёта read-only.

## 4. Чек-лист анти-срочности (обе волны)

Проверено по обоим наборам сообщений: дедлайны/обратные счётчики — **нет** · «успейте/осталось» — **нет** ·
навал отзывов — **нет** (один точечный `{testimonial}`, MG-supplied) · лычаги на месте: свой темп,
записи без ограничения срока, оплата по периодам, имя преподавателя. Данные-основание:
[MARATHON_DIAGNOSTIC_2026 §3](https://github.com/gasyoun/Uprava/blob/main/custdev/MARATHON_DIAGNOSTIC_2026.md).

## 5. Что НЕ менялось

Волна 1 (тексты и логика) · платёжные пути · Day 1–3 дрип · расписание крона
(`marathon:deliver-warm-tail`, каждые 15 мин) · идемпотентность и обработка таймаутов (H2701).

_Dr. Mārcis Gasūns_
