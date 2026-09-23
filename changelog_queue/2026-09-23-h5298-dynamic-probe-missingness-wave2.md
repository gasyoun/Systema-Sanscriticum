# H5298: динамические (не-командные) probe-сеймы — три немых пропуска починены

_Created: 23-09-2026 · Last updated: 23-09-2026_

Wave 2 остаточного риска [H5061](https://github.com/gasyoun/Systema-Sanscriticum/pull/2664)
(«dynamic non-command metric paths outside these eight seams не censused»):
перепись слушателей/observer'ов/`->onFailure()`-колбэков вне восьми командных
probe-сеймов wave 1, найдено и починено три живых немых пропуска той же
самой генеральной проблемы («молчаливый skip = молчаливый пропуск»):

- [`ScheduleFailureSignal::report()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/ScheduleFailureSignal.php)
  (домовый пейджер для 7 денежных cron-команд через `->onFailure()`): при
  отсутствии `super_admin`/`admin`/`accountant`-получателя ветка Filament-уведомления
  тихо возвращалась после `Log::critical` — теперь пишет `Log::warning`
  (`not_supported`).
- [`TechnicalIssueNotifier::newTechnicalIssue()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/TechnicalIssueNotifier.php)
  (дежурный колокольчик очереди «Техника», собственный докблок сравнивает
  себя с `telegram-support:healthcheck` из H5061): тот же класс дефекта —
  теперь тоже loud `not_supported`.
- [`ScheduleObserver::sendToN8n()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Observers/ScheduleObserver.php)
  (синк расписания в n8n на каждый CRUD): пустой webhook-URL молчал — теперь
  `Log::info` (`not_supported`), различимо от «отправлено, ответа нет».

Перепись + метод + разбор reachability:
[docs/DYNAMIC_PROBE_MISSINGNESS_CENSUS_2026-09-23.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/DYNAMIC_PROBE_MISSINGNESS_CENSUS_2026-09-23.md).
Регрессии на каждый дефект:
[tests/Feature/Support/DynamicSeamMissingnessRepairsTest.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Support/DynamicSeamMissingnessRepairsTest.php)
(5 тестов). Ничего не меняется в целях уведомлений, n8n-вебхуке или кодах
выхода — только громкость на ранее немых ветках. Существующие тесты
`MoneyCronScheduleHooksTest`, `ScheduleWebhookGuardTest`,
`TechnicalIssueRouterTest`, `ProbeMissingnessContractTest`,
`ProbeMissingnessRepairsTest` — 35 тестов, зелено без изменений.

_Гасунс_
