_Created: 08-09-2026 · Last updated: 08-09-2026_

# H4396: ремонт трёх дыр пейволл-гейтов — серверные ворота записи, серверный счётчик lila-бюджета, expiry-предикат условного доступа (OxAlpha z-ai/glm-5.3-flash, 08-09-2026)

Census [PAYWALL_CENSUS_2026-09-08](https://github.com/gasyoun/Uprava/blob/main/reports/PAYWALL_CENSUS_2026-09-08.md) (вердикты MG 08-09, волна 1 карточка 3 «Чинить топ-дыры») — [H4396](https://github.com/gasyoun/Uprava/blob/main/handoffs/H4396-OxAlpha_Systema-Sanscriticum_paywall-gate-repair_08.09.26.md). PR [#2452](https://github.com/gasyoun/Systema-Sanscriticum/pull/2452) merged (3153ba1); все прод-флаги и .env не тронуты.

## 1. Unlisted-YT: «гейт на странице, не на видео» → серверные ворота видеопейлоада

- Дыра §A3: гейт жил на странице урока, а сырые unlisted-ID YouTube/RuTube лежали в HTML готовыми строками — один оплаченный месяц экспонировал ID бэк-каталога навсегда (unlisted на стороне YouTube не отзываем; зеркала собирают ID из HTML).
- Новый [`RecordingGateController`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/RecordingGateController.php), маршрут `GET /c/{slug}/u/{lessonId}/video/{player}` (вне auth-группы, до catch-all): на КАЖДУЮ загрузку заново проверяет грант > клуб > группу > оплату (канонический `getUserUnlockedTariffs`) > H3916-членство (`RecordingAccessPolicy`, решение пишется в `MembershipAccessVerdict` с surface `web_recording_gate`), и только тогда 302 на embed с `Cache-Control: private, no-store`.
- Страница урока и публичный preview плеера больше не содержат сырых ID: iframe'ы грузят ворота; доступность плееров считается серверно через `StudentController::parseVideoId` (теперь public static — прецедент H3308). `shop/preview` — тот же маршрут: preview-урок ворота пускают гостю.
- **Ноль изъятия бесплатных возможностей**: is_free/is_preview отдаются всем включая гостя; вывод на главную (shownOnMain) не тронут; staged rollout записи не менялся (флаги `membership_recording_*` на проде OFF, `RecordingAccessPolicy` при OFF пускает — прод-инертно).

## 2. Lila-ворота: бюджет «5 бесплатных раундов» переехал на сервер

- Дыра: gate.js считал бюджет ТОЛЬКО в localStorage — очистка сбрасывала.
- Новый [`LilaGateController`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/Api/LilaGateController.php): `GET /api/games/budget` + `POST /api/games/round` (web-сессия, throttle 60/1, POST CSRF-exempt как games/event). Бюджет живёт в `game_events` под новым событием `round` (EVENT_POINTS 0 — лидерборд не тронут); воронковый `complete` в бюджет не считается — перезагрузки страницы не сжигают раунды.
- Ключ счётчика — sha256 от **web-сессии** (не от клиентского payload, не PII — контракт R20 соблюдён): очистка localStorage бюджет не сбрасывает; новая сессия = семантика «на устройство», как и раньше. Ворота остаются funnel-наддувом, не DRM.
- [gate.js](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/lila/gate.js): сервер первичен (used = max(local, server) — никогда не строже, чем было), при недоступности сервера — прежнее локальное поведение (голые статические хосты работают, контракт H1360 сохранён). Залогиненные студенты не запираются (проверка та же, `/api/games/auth`). `.sgx-gate` разметка и стена — байт-в-байт прежние, `telemetry.js` не тронут.

## 3. Expiry-предикат на payment-keyed доступ (census §C.1 verify → root-cause fix)

- Verify: предиката НЕТ. `getUserUnlockedTariffs` берёт `paid()` без фильтров; conditional-платёж («доступ под обещание», is_conditional=true, status=paid) открывает уроки навсегда — `promises:expire` менял только статус обещания (аудит 06-08 D9/#11/#12, спека 5 не закрыта H2304).
- Fix: [`Payment::scopeWithAccessExpiry`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Payment.php) за флагом `conditional_access_expiry` (дефолт **OFF** — money-контур, прод-флип отдельный ops-шаг, H2085 discipline; та же постановка, что `grant_access_fail_closed`): conditional-ключи живут пока обещание живо (status=active И promised_at не прошёл — дата, а не статус дневного демона); истёкшие/просроченные/отменённые/осиротевшие обещания ключей не дают; **реальные платежи предикат не трогает** («оплатил = владеет навсегда»).
- Применён в каноническом `StudentController::getUserUnlockedTariffs` (плеер/курс/ассеты через LessonGate наследуют) и `Api/CabinetController` (локальная копия заменена общим доступ-листом — наименьший срез audit spec 14). `HomeworkController` держит СВОЙ запрос (намеренно клубно-слепой, инвариант §6 пинит [ClubEntitlementAccessTest](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Membership/ClubEntitlementAccessTest.php)) и получил только предикат.
- `promises:expire` печатает `Conditional grants on expired promises: N (…)` — ops-сигнал «сколько доступов ждёт флипа».

## 4. Тесты и приёмка

- Новые: [RecordingGateTest](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Access/RecordingGateTest.php) 12/12 (гость free/preview пускается, гость платный 404, покупатель 302, член без оплаты 404, H3916-запрет при enforce, expiry-предикат на воротах, rutube-токен, страница без сырых ID) · [LilaDrillGateTest](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Access/LilaDrillGateTest.php) 8/8 (сессионный ключ переживает «очистку localStorage», per-family/per-session, залогиненные не пишут, telemetry-complete не считается, контракт JS) · [ConditionalAccessGateExpiryTest](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Access/ConditionalAccessGateExpiryTest.php) 9/9 (флаг OFF пинит текущее поведение; ON: expired/overdue-active/cancelled/orphan закрывают, живое обещание и реальный платёж открывают; API применяет тот же предикат; сигнал в promises:expire).
- Обновлены с сохранением интента (сырые ID → маркеры ворот): RecordingAccessPolicyTest 3, KinescopePilotTest 2. **Ворификатор хэндоффа**: `php artisan test --filter=Gate` — 117/117 зелёных; полный suite 5372 — зелёный кроме 4 pre-existing средовых падений (teacher-facet/скриншоты; на чистом дереве падают так же). Pint clean. CI: MySQL 8.4 finance/webhook — pass; 3 красных чека (composer security audit, changelog-release gate, environment inventory) — pre-existing на main, от PR не зависят.
- **Прод-флип `CONDITIONAL_ACCESS_EXPIRY=true` остаётся за человеком** (money-adjacent) — GTD-строка заведена.

_Dr. Mārcis Gasūns_
