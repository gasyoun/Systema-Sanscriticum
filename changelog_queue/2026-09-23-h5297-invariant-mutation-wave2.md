# Invariant mutation testing wave 2 — три новых границы, ни одного продовского дефекта (H5297)

_Created: 23-09-2026 · Last updated: 23-09-2026_

- **Тесты, не продовый код.** [PR #2798](https://github.com/gasyoun/Systema-Sanscriticum/pull/2798) (OxAlpha `opencode/z-ai/glm-5.3-flash`, 23-09-2026) продолжает дисциплину [PR #2683](https://github.com/gasyoun/Systema-Sanscriticum/pull/2683) (H5093) и [PR #2684](https://github.com/gasyoun/Systema-Sanscriticum/pull/2684) (H5094) на трёх новых границах — ни один пример (CSP `frame-ancestors`, журнал реавторизации, duplicate-token, FormulaGuard, duplicate flash, payment-delete) не переиспользован.
- **A. `GatedAssetController` (H3308).** Для гейта стенограммы/материалов/справочных файлов урока (`LessonGate::canWatch()`, та же цепочка, что у плеера) не существовало ни одного теста. Новый [`H5297GatedAssetTranscriptFenceTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Sandbox/H5297GatedAssetTranscriptFenceTest.php) доказывает: неоплативший студент получает 404, а байты стенограммы не просачиваются в тело ответа.
- **B. `VerifyMaxMagnetWebhook`.** Существующий тест `wrong_secret_in_url_returns_403` проверял только код ответа. Усилен: `Bus::assertNotDispatched(ProcessMaxMagnetUpdate::class)` ловит баг порядка «сначала диспетчеризация, потом 403» — статус остаётся 403, но джоб уже ушёл бы в очередь.
- **C. `impersonation-banner.blade.php`.** Существующий тест плашки режима использовал обычное имя — зелёный что с `{{ }}`, что с `{!! !!}`. Новый тест ставит имя с `<script>…</script>` и проверяет, что сырой тег не долетает до ответа: плашка вставляется в КАЖДЫЙ layout сырой строковой склейкой (`ImpersonationGuard`), регрессия экранирования — это stored XSS, а не косметика.
- **Ни одна из трёх посаженных мутаций не вскрыла реальный продовский дефект** — все три границы уже были правильно закрыты; разрыв был в тесте. Ни одна мутация не закоммичена, чистый diff к `origin/main` — только тесты + журнал мутаций [`docs/H5297_MUTATION_LEDGER_INVARIANT_TEST_WAVE2_23-09-2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/H5297_MUTATION_LEDGER_INVARIANT_TEST_WAVE2_23-09-2026.md).
- 26/26 PHPUnit зелёные (129 assertions), Pint чист, независимый верификатор (Explore-агент) — PASS.

_Гасунс_
