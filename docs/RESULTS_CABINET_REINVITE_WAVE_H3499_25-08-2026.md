# Волна повторных приглашений в кабинет + публичный гид — результаты переписи и протокол

_Created: 25-08-2026 · Last updated: 25-08-2026_

**Handoff:** H3499 (OxAlpha `x-preview-f-free`) · **Разрешение MG:** 25-08-2026 («разрешить волну --resend — да»; публичный гид — роутом на samskrte.ru, не github.io; брифинг куратора — порциями 5 будней подряд).

## Перепись прода (read-only SSH `193.232.229.92:/var/www/html`, HEAD `632bb1fe`, 25-08-2026)

| Метрика | Значение |
|---|---|
| Never-logged-in с выданным доступом (популяция `students:send-login-invites`) | **198** |
| Из них ещё не приглашались / уже приглашались | 0 / **198** (штампы 13-07 → 24-08-2026, еженедельный батч пн 10:00 MSK) |
| Каналы достижимости когорты | Telegram **0** · VK **0** · SMS-способных **85** (`SMS_RU_API_ID` на проде НЕ задан → канал отключен) · только email **113+85=198** |
| Платившие (`payments.status=paid`) | 933 всего · никогда не входили **722 (77%)** · входили 211 (22.6%) |
| Access-stamp когорта («[Доступ отправлен») | 379 · входили **181 (47.8%)** |
| Приглашались когда-либо (`cabinet_invite_sent_at`) | 221 · вошли после приглашения **23 (10.4%)** — против 4/219 ≈ 1.8% на срезе H2380 07-08 |
| `MAIL_FROM_*` на проде | `rusamskrtam@yandex.ru` / **«ORS LMS»** (меняется на «Школа санскрита ОРС» этим же проходом) |

Вывод: автоматическая еженедельная капля (50/нед) конвертирует, но медленно; вся когорта достижима
только email (TG/VK у never-logged-in нет by design, SMS не настроен) — поэтому MG одобрил
однократную намеренную волну `--resend` поверх идемпотентных штампов.

## Протокол волны (правило прогрева P0: ≤100–200 писем/день)

1. День 1 (сегодня): dry-run → `php artisan students:send-login-invites --send --resend --limit=100`.
2. День 2: остаток (~98) — GTD `@DO` строка, тот же батч.
3. Мониторинг: bounce/spam-жалобы; при росте ошибок — стоп и разбор до следующего батча.

Письмо волны — NOT пароль (старые пароли хранятся только хэшами и невосстановимы):
[`Password::sendResetLink`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/SendCabinetInvites.php)
с темой «Вход в личный кабинет ОРС» и одноразовой ссылкой «задайте пароль». Копировать/набирать
пароль из старого письма больше не нужно нигде — это снимает и жалобу «руками набранный выдал ошибку».

## Что поставлено кодом (этот PR)

1. **Публичный гид кабинета `GET /help/kabinet`** ([PublicCabinetGuideController](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/PublicCabinetGuideController.php))
   — тот же источник [`docs/STUDENT_CABINET_GUIDE_RU.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_GUIDE_RU.md),
   что кабинетный `/dvaram/help`, но без auth (layouts.articles, CTA «Войти», строго до catch-all).
   Закрывает круговую ссылку: анонсы вели не вошедших на страницу за логином.
2. Само-ссылка гида `samskrte.ru/dvaram/help` → `https://samskrte.ru/help/kabinet` (2 вхождения) — публичный адрес верен на обеих поверхностях.
3. Тесты: [`PublicCabinetGuideTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/PublicCabinetGuideTest.php) (гость видит гид+CTA, authed тоже, источник не ссылается за логин). `--filter="PublicCabinetGuideTest|StudentCabinetGuideCoverageTest"` → 9 passed / 1 skipped (кадры, GUIDE_SHOTS_OPTIONAL как в CI); Pint clean.

## Сопряжённые поверхности (вне репо)

- ORS-FAQ: шаблоны `/кабинет-вход`, `/кабинет-не-нашли`, `/кабинет-анонс` переводятся на публичный гид ([Telegram_templates.md](https://github.com/gasyoun/ORS-FAQ/blob/main/Telegram_templates.md)).
- Uprava: брифинг куратора «5 будней × порция + тест H3215» (`docs/BRIEFING_CURATOR_CABINET_5_DAYS_25-08-2026.md`), GTD-строки волны и публикаций.

_Dr. Mārcis Gasūns_
