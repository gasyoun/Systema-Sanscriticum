_Created: 09-09-2026 · Last updated: 09-09-2026_

# H4448: вариант A/B пишется в storefront_events — next_step-строки больше не все variant=null (OxAlpha z-ai/glm-5.3-flash, 09-09-2026)

Process-mining волна 2 (остаток [H4417](https://github.com/gasyoun/Uprava/blob/main/handoffs/H4417-OxAlpha_Uprava_process-mining-business-trio_08.09.26.md), GTD-строка 08-09): замер 08-09 показал, что `storefront_events.variant` пуст на всех 73 747 строках — назначение варианта нигде не доходило до записи, и любое сравнение вариантов было бы выдумкой (вердикт волны 1: NOT MEASURABLE). Разбор кода показал: механизм назначения уже есть ([FlagshipExperiments::ctaVariant](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/FlagshipExperiments.php) — cookie, 50/50 при первом касании, паттерн H2010/H2762), дыра только в двух точках записи эксперимента `next_step`. PR [#2464](https://github.com/gasyoun/Systema-Sanscriticum/pull/2464).

- **Прошиты обе точки записи next_step**: `FlagshipExperiments::recordCardImpression()` (CARD_IMPRESSION) и `StorefrontAnalyticsController::nextStep()` (NEXT_STEP_CLICK) теперь передают `variant: ctaVariant($request)` — назначение при первом касании, тот же единственный механизм, второй не заводился. Клик несёт тот вариант, который посетителю реально показан.
- **CTA_AB-строки не тронуты**: sample_play/begin_checkout уже пишут `ctaVariantFromRequest()` (read-only, с H2762 #1724); их пустые варианты в замере волны 1 — честные null посетителей, оформивших заказ без экспериментальной cookie, а не дыра проводки.
- **Не тронуто**: ключ дневной дедупликации (вариант в него не входит), гейты флагов, окно эксперимента, R12/R15-изоляция.
- **Тесты**: [FlagshipExperimentsTest](https://github.com/gasyoun/Systema-sanscriticum/blob/main/tests/Feature/Shop/FlagshipExperimentsTest.php) +3 (первое касание назначает a/b и строка несёт вариант; клик с cookie «b» пишет вариант «b»; дневная дедупликация выживает при прошитом варианте). Полный сюит: 5449 тестов / 27 581 утверждений, 4 падения — проверены на чистом базовом дереве, те же самые (окружение), диф добавляет ноль.

_Dr. Mārcis Gasūns_
