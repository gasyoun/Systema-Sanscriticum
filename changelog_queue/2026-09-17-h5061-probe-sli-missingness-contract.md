# Probe/SLI missingness контракт: тихие пропуски и 0-коэрция починены (OxAlpha `opencode/z-ai/glm-5.3-flash`, 17-09-2026)

H5061 — извлечение и enforcing контракта наблюдаемости, продемонстрированного PR #2526/#2565/#2645
(H4648 coverage-partial, H4672 money-axis SLI): проба/метрика обязана различать `value` (настоящий ноль —
это value), `unavailable`, `not_supported`, `pending`, `failed` и `partial`; отсутствие конфигурации —
громкое и машинночитаемое; зелёный прогон снимает залипший fail. Канзус активных швов —
[docs/PROBE_SLI_MISSINGNESS_CENSUS_2026-09-17.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PROBE_SLI_MISSINGNESS_CENSUS_2026-09-17.md)
(8 швов: cabinet:probe, оба money-SLI, heartbeat:ping, telegram-support:healthcheck, hindi-пробы,
дедман-мониторы).

- **Словарь состояний** [`app/Support/Observability/ProbeOutcome.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/Observability/ProbeOutcome.php):
  шесть канонических состояний, `partial` намеренно НЕ зелёный (частичное покрытие обязано отличаться
  от полного — класс H4648). Второго мониторингового стора нет: адресаты алертов (TG, Better Stack/
  healthchecks, TSV, `cabinet_probe_runs`) не тронуты.
- **Контрактный тестовый слой** (переиспользуемый): trait
  [`tests/Concerns/AssertsProbeMissingnessContract.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Concerns/AssertsProbeMissingnessContract.php)
  + сюита [`tests/Feature/Support/ProbeMissingnessContractTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Support/ProbeMissingnessContractTest.php)
  с засеянными нарушениями ([`tests/Support/SeededViolationProbes.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Support/SeededViolationProbes.php)):
  красные квитанции на silent-skip, 0-коэрцию и sticky-never-clears; на чистом дереве — зелёная.
- **Починены 5 доказанных нарушений** (каждому — отдельный регрессионный тест в
  [`tests/Feature/Support/ProbeMissingnessRepairsTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Support/ProbeMissingnessRepairsTest.php)):
  1) `MoneySliAlerter::heartbeat` с пустым URL молча возвращался — теперь громкий
  `Log::warning` (`not_supported`); 2) `money:sli-synthetic-pay` при выключенном фиче-флаге выходил
  зелёным без записи в метрику — теперь TSV-строка `status=not_supported` (раз в сутки, без спама) +
  громкий лог, heartbeat по-прежнему молчит (тишина и есть дедман); 3) `money:sli-hourly-reconcile`
  при OFF — громкий лог вместо тихого комментария (TSV на часовой каденции сознательно не спамим);
  4) `heartbeat:ping` без `HEARTBEAT_PING_URL` — добавлен машинночитаемый `Log::warning`;
  5) `telegram-support:healthcheck` при нуле включённых аккаунтов был `info` + зелёный (отсутствие
  данных, скоэрченное в зелёное) — теперь `warn` + лог (`not_supported`), exit прежний (незабоченный
  шов не роняет прогон планировщика).
- **Не тронуто:** true-zero семантика (настоящий ноль остаётся `value`), exit-коды команд,
  прод-данные. `MoneySliSyntheticPayCommandTest` 2/3 фейла — подтверждено ДО-существующими на чистом
  дереве (`git stash -u` → те же 2), локальная среда healthy-run вебхука, вне скоупа H5061.
