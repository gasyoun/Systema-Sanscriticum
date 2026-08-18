# Решение: сборка `combined-images.pdf` уходит с пути запроса (H3095)

_Created: 18-08-2026 · Last updated: 18-08-2026_

Развилка из [H3095](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3095-Opus_Systema-Sanscriticum_homework-pdf-build-off-request-path_18.08.26.md)
(Opus 5 — «Унести сборку combined-images.pdf с пути запроса в очередь»),
остаток [H3092](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H3092-Opus_Systema-Sanscriticum_homework-images-pdf-oom-kills-submission_18.08.26.md)
(Opus 5 — «Сборка PDF из картинок роняет сдачу ДЗ по памяти»).

## Что чинили

[`HomeworkService::recordSubmission()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/HomeworkService.php)
после коммита транзакции делал подряд:

```
app(HomeworkImagePdfService::class)->rebuildQuietly($submission);   // необязательное
$this->notifyTeacher($submission, $isResubmission);                 // ОБЯЗАТЕЛЬНОЕ
```

Порядок и был дефектом: обязательное стояло **за** необязательным и наследовало
все его способы умереть. Исчерпание памяти — фатальная ошибка PHP, её не ловит
`try/catch` внутри `rebuildQuietly()`, поэтому 18-08-2026 падал весь POST сдачи:
работа уже лежала в базе, студент получал 500 и слал заново по кругу, а
проверяющий о работе не узнавал. H3092 убрал известный вход (ужатие страниц до
1600 px) — но не класс.

## Что выбрано: **вариант B** — «уведомление первым, вложение опционально»

Сборка ушла в
[`BuildHomeworkImagesPdfJob`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Jobs/BuildHomeworkImagesPdfJob.php)
(очередь `imports`, соединение `redis-long`, `tries = 1`, `timeout = 300`).
Уведомление проверяющего не зависит от её исхода ни при каком раскладе.

| Вариант | Почему не он |
|---|---|
| **A. `Bus::chain([Build, Notify])`** | При `tries = 1` на `supervisor-long` падение первой job убивает вторую — тот же дефект, просто переехавший в очередь. |
| **C. Одна job, notify в `finally`/`failed()`** | При **фатальной** ошибке (тот самый OOM) `finally` не выполняется. Остаётся только `failed()`, а он у Horizon срабатывает не мгновенно — уведомление всё ещё привязано к сборке. |
| **B. Notify независимо** | Единственный вариант, где доставка уведомления не зависит **ни от одного** исхода сборки. |

### Три опоры, на которых стоит B

1. **Письмо уже было в очереди.**
   [`HomeworkSubmittedMail`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Mail/HomeworkSubmittedMail.php)
   реализует `ShouldQueue` и садится на `mailing`; `attachments()` считается на
   воркере в момент отправки. Единственное, что тянуло PDF на путь запроса, —
   `$this->hasImagesPdfAttachment = …->canAttachToMail(…)` в **конструкторе**.
   Теперь это метод `hasImagesPdfAttachment()`, который считается лениво, и
   письму больше не нужен готовый PDF в момент постановки в очередь.
2. **У воркера другой `memory_limit`** — CLI-ini (768M на `.92`) против 128M у
   php-fpm. Тот же вход на воркере имеет вшестеро больше запаса.
3. **`tries = 1`** на `supervisor-long`: упавшая job не повторяется, поэтому
   уведомление нельзя ставить в зависимость от успеха сборки.

### Цена варианта B и чем она погашена

Гонка: письмо может уйти раньше, чем PDF собран, и уехать без вложения. Это
принято сознательно и стоит дёшево:

- `attachments()` и `hasImagesPdfAttachment()` считаются **вместе** на воркере,
  поэтому текст письма никогда не обещает вложения, которого нет;
- у проверяющего в любом случае есть ссылка «Проверить работу», а по
  `homework.submission.images-pdf`
  ([`HomeworkController::downloadImagesPdf()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/HomeworkController.php))
  PDF досбирается лениво при открытии — этот путь остаётся синхронным
  сознательно: там человек ждёт именно файл;
- постановка в очередь идёт **первой** (до `notifyTeacher()`) только чтобы дать
  сборке фору; от её успеха уведомление отвязано отдельно — см. ниже.

### Почему постановка в очередь обёрнута в `try/catch`

`queueImagesPdfRebuild()` глушит исключения **самого `dispatch()`**, а не сборки.
Это делает обещание безусловным, а не зависящим от драйвера очереди: под
`QUEUE_CONNECTION=sync` (тесты, чужая среда, локальная отладка) job выполняется
прямо внутри запроса, и без страховки брошенное сборкой исключение прилетело бы
в POST ровно как раньше. Регрессия закреплена тестом
[`HomeworkPdfFailureDoesNotLoseNotificationTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Homework/HomeworkPdfFailureDoesNotLoseNotificationTest.php),
который гоняется именно на `sync` — самом враждебном из реальных раскладов
(проверено: без `try/catch` тест краснеет).

### Ошибки сборки больше не глушатся

Job вызывает `rebuild()`, а не `rebuildQuietly()`: раз от сборки больше ничего не
зависит, падение полезнее видеть в `/horizon` (failed jobs), чем в
`Log::warning`, который никто не читает.

## Что переехало заодно

Все синхронные вызовы `rebuildQuietly()` в `HomeworkService` заменены на
постановку job: удаление файла студентом, удаление файла штатом, очистка всех
файлов, перенос файла между сдачами (две сборки). Два из них стояли **внутри**
`DB::transaction()` — то есть держали транзакцию открытой на всё время сборки
PDF; теперь job ставится после коммита.

Синхронной осталась одна точка — `HomeworkController::downloadImagesPdf()`
(ленивая досборка при открытии), и это правильно: там ответом является сам файл.

## Что этот проход НЕ трогал

- **`memory_limit` php-fpm** — подъём замаскировал бы класс, а не убрал.
- **Пересборка PDF задним числом** — у сдач до 08-08-2026 PDF нет законно.
- **Новая очередь / новый supervisor** — `imports` уже живёт на
  `supervisor-long` с `timeout 600`, инфраструктура не меняется, деплою нечего
  донастраивать.

## Известный остаток (не в охвате H3095)

`HomeworkNotifier::notifyReviewer()` шлёт Telegram синхронно и **без**
`try/catch` (в отличие от `HomeworkNotifier::opened()`). Недоступный Telegram
по-прежнему может вернуть студенту 500 уже после сохранения работы — тот же
класс «обязательное за необязательным», но другая ветка. Письмо и
database-уведомление к тому моменту уже отправлены, поэтому проверяющий работу
не теряет.

_Dr. Mārcis Gasūns_
