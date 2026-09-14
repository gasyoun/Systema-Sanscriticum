# Worktree phpunit-гочи: PendingCommand-мок съедает expectsOutputToContain; эмодзи без U+FE0F ломает byte-матч

_Created: 12-09-2026 · Last updated: 12-09-2026_

Два дефекта, найденных в H4629 (payout:run три режима), при тестировании командного вывода в worktree-прогонах:

## 1. `expectsOutputToContain` при >1 ожидании —-framework bug (Laravel 13.30.1)

`$this->artisan(...)->expectsOutputToContain(A)->expectsOutputToContain(B)` — проверяется только первое ожидание, остальные ВСЕГДА падают («Output does not contain …»), даже когда строка в выводе есть.

Причина: `PendingCommand::mockConsoleOutput()` регистрирует Mockery-ожидание `doWrite` с `atLeast()->times(0)` на каждый substring. `atLeast()->times(0)` не исчерпывается никогда → первое ожидание перехватывает ВСЕ вызовы `doWrite`, и последующие ожидания не получают ни одного вызова; `verifyExpectations()` падает на первом незакрытом.

Рабочий путь (используется в `PayoutRunTest` с H4629): `Artisan::call(...)` + `Artisan::output()` + `assertStringContainsString` в цикле. Плюс бонус: прямой доступ к `$out` даёт strpos-проверки порядка (хронология ленты).

## 2. Эмодзи-префиксы вывода: вариационный селектор U+FE0F

Марин-стиль `🔹️` в чате = `F0 9F 94 B9 EF B8 8F` (U+1F539 + **U+FE0F**). При переписывании форматтера легко потерять селектор (`F0 9F 94 B9` без него) — тесты на вывод падают с идентично выглядящим текстом: `expectsOutputToContain`/`assertStringContainsString` — byte-матч, не визуальный.

Проверка: `grep -o "🔹" файл | head -1 | xxd`. Фикс: `perl -CSD -i -pe 's/\x{1F539}(?!\x{FE0F})/\x{1F539}\x{FE0F}/g'`.

## Как не наступить снова

- Ожидания вывода artisan в тестах — через `Artisan::call`/`Artisan::output`, не через chained `expectsOutputToContain` (>1).
- Любой эмодзи в ожидаемом выводе — xxd-сверка байт с источником (чат Марины и т.п.).

_Dr. Mārcis Gasūns_
