# Club checkout pre-flight (H3331) — отчёт и go/no-go чеклист

_Created: 22-08-2026 · Last updated: 22-08-2026_

Исполнитель: OxAlpha (`x-preview-f-free`). Хендофф: [H3331-OxAlpha_Systema-Sanscriticum_club-checkout-preflight-event-chain_22.08.26.md](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H3331-OxAlpha_Systema-Sanscriticum_club-checkout-preflight-event-chain_22.08.26.md).
Контур «оплата → членство → группа → каталог» на проде, уровень 1 (без реальных списаний).

## 1. Результаты проверок (22-08-2026)

| # | Проверка | Результат | Детали |
|---|---|---|---|
| 1 | Страница клуба | ✅ 200 | `https://samskrte.ru/klub` |
| 2 | Чекаут клубного тарифа | ✅ 200 | `/checkout/5038` («Клуб — месяц», легаси ₽1 500) |
| 3 | `membership:rehearse` | ✅ PASS (выполненные шаги) | шаги 1, 1c, 2, 3, 4, 4b PASS; 5–8 SKIP (нужен `--apply --user=`) |
| 4 | Тарифная матрица контракта | 🔴 **НЕ СООТВЕТСТВУЕТ** | см. §2 — это главный вывод |
| 5 | Реальные покупки клуба | подтверждено: **0** | `payments course_id=444`: 0 · `club_memberships`: 0 |
| 6 | Почта M12 | ⚠️ деградация жива | `failed_jobs` за 3 дня: 6 (Яндекс 554) |

## 2. Главный вывод: тировая лестница ещё не активирована

На проде ровно **3** membership-тарифа (курс #444 «Клуб») — легаси-цены запуска 16-08:

| Тариф | На проде | Контракт [трёх тиров](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MEMBERSHIP_THREE_TIER_RECORDING_GATE_2026.md) |
|---|---|---|
| Club 1 мес | ₽1 500 (id 5038) | ₽2 000 |
| Club 3 мес | ₽4 000 (id 5039) | ₽5 700 |
| Club 12 мес | ₽15 000 (id 5040) | ₽20 400 |
| Basic 1/3/12 мес | **ОТСУТСТВУЮТ** | ₽1 000 / ₽2 850 / ₽10 200 |

Это **не поломка**: `membership:rehearse` шаг 1b прямо показывает `MEMBERSHIP_TIERED=OFF`
(dark-deploy) — ступень активации трёх тиров из runbook H2744 ещё не запускалась
(classify-tiers → шесть тарифов → флаг). Но она расходится с ратифицированной сегодня
лестницей Free / Basic ₽1 000 / Club ₽2 000 ([MONETIZATION_PLAN_2026H2 §0](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MONETIZATION_PLAN_2026H2.md)):
публично сейчас продаётся один тир ₽1 500.

## 3. Go/no-go чеклист для человека

**GO** (можно уже сегодня): живой чекаут **легаси**-клуба ₽1 500 — платёжный путь отрепетирован,
страницы рендерятся; покупка пройдёт по текущей витрине.

**НО-GO до решений MG** (money row, [план §5](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MONETIZATION_PLAN_2026H2.md)):
1. Дата активации тиров: когда перевести витрину на Free/Basic/Club (до марафонского пуша 28-08? до VK-теста?).
   Цена клуба в контракте **выше** легаси (2000 vs 1500) — переход = публичное изменение цен.
2. Активация по runbook: `membership:classify-tiers` dry → apply → создать 6 тарифов →
   `MEMBERSHIP_TIERED=true` → `config:cache` → `membership:rehearse` всё зелёное.
3. Только после этого — единственный живой чекаут человека как финальная приёмка.
4. Почта M12: до маркетингового пуша починить или принять (покупатель без письма-подтверждения).

## 4. Что НЕ делалось намеренно

Сквозной прогон событий на staff-юзере (шаги 5–8) не запускался: при MEMBERSHIP_TIERED=OFF он
отрепетировал бы легаси-сценарий, а его результат всё равно перекрывается решениями §3.1–2.
Запуск полноценной цепочки с реальными events на проде пишет строки в money-таблицы — только после
правил MG по §3 (гвард хендоффа: тарифы/цены не менять).

_Dr. Mārcis Gasūns_
