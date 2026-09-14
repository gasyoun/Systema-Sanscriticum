_Created: 26-08-2026 · Last updated: 05-09-2026_

# Regression agreement: engine_py vs ClassifierPrecisionTest named cases

_Created: 26-08-2026 · H3528 supplementary evidence · rules/v1 @ h3528-baseline-reports_

The 9 named regression cases verbatim from
[ClassifierPrecisionTest.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Support/ClassifierPrecisionTest.php)
(H3394, the PHP-gated precision canon) routed through the Python reference
engine of this package.

**Agreement: 9/9** — route-equivalent semantics across engines;
F-split (homework_progress) preserved; null-path cases stay null.

| text | legacy | expected topic | got topic | agree |
|---|---|---|---|---|
| сколько стоит курс и где ссылка | D | payment_billing | payment_billing | yes |
| ссылку на оплату не нашла | D | payment_billing | payment_billing | yes |
| сколько будет стоить курс по календарям? | D | payment_billing | payment_billing | yes |
| есть ли функция рассрочки или по частям оплата? | D | payment_billing | payment_billing | yes |
| Добрый вечер! Не могу открыть занятия по паролю 102. Он изме | E | access_login | access_login | yes |
| куда мы должны прикреплять домашнюю работу по занятию 3? | F | homework_progress | homework_progress | yes |
| Жаль, тогда прошу сделать возврат | None | None | None | yes |
| Возврат | None | None | None | yes |
| Намо намах! | None | None | None | yes |

Machine-readable sidecar: `reports/h3528-regression-agreement.json`.

_Dr. Mārcis Gasūns_
