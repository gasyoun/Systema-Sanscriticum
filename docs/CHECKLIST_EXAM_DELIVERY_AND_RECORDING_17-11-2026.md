_Created: 17-11-2026 · Last updated: 17-11-2026_

# Чек-лист «Экзамен: проведение и запись результатов» (H8396)

Advisory-чеклист, две секции: **A** — студенческий экзамен (кондуit оценки: n8n → Google Sheet → webhook в Systema), **B** — pk72 hiring-exam (проверка экзаменатора-кандидата). Составлено 17-11-2026, handoff [H8396](https://github.com/gasyoun/Uprava/blob/main/handoffs/H8396-OxAlpha_Systema-Sanscriticum_checklist-gap-census-top5_17.11.26.md); исполнитель GLM 5.3 Flash (`zai-coding-plan/GLM-5.3-Flash`, OpenCode).

## A. Студенческий экзамен

Машина-сторона проверена по коду и надёжна: [`ExamScoresWebhookController`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/Webhooks/ExamScoresWebhookController.php) на `POST /api/webhooks/exam-scores`, подпись `verify.n8n.examscores`, дедуп по `event_hash` через `PaymentWebhookEvent`. Человеческая сторона — этот чеклист.

### A1. До экзамена

- [ ] Экзамен привязан к курсу/уроку в Systema (какой урок финальный, какая форма сдачи).
- [ ] Ученики уведомлены: дата, время, формат, что разрешено/запрещено.
- [ ] Google Sheet преподавателя подготовлен: колонки (ученик, дата, балл, вердикт, комментарий) согласованы с n8n-синком `exam-scores-sync` — имена листа/колонок не менять после старта.
- [ ] n8n-воркфлоу `exam-scores-sync` активен, креды живы (источник — тот же Google Sheet).

### A2. Проведение

- [ ] Запись занятия: Zoom `auto_recording: cloud` включён; [`ZoomWebhookController`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/Webhooks/ZoomWebhookController.php) + `AttendanceRecorder` клада посещаемость в `webinar_attendances` (ключ `schedule_id` + `participant_uuid`).
- [ ] Инциденты (отключения, пересдачи по договорённости) фиксируются в тот же день в Sheet-комментарии.

### A3. Запись результатов и синк

- [ ] Каждому ученику — ровно одна строка в Sheet (пересдача = новая строка с пометкой, не правка старой).
- [ ] После заполнения: n8n POST доставлен — в Systema появилась запись; дедуп `event_hash` означает, что повторный пуш той же строки безопасен (дубля не создаст).
- [ ] Сверка N строк Sheet ↔ N записей в Systema; расхождение = разбор по A4.

### A4. Расхождения

- [ ] Строка в Sheet, записи нет → повторный пуш (безопасен по дедупу); не помогло — проверить подпись/активность воркфлоу.
- [ ] Запись есть, строки нет → ручная запись в Sheet задним числом с пометкой источника; из Systema вручную не удалять.
- [ ] Никогда не заводить результат вручную в Systema напрямую в обход синка (ломает единый источник и дедуп).

## B. pk72 hiring-exam (проверка exercise-checker)

Манифест: [`docs/hiring/pk72_exam_manifest.example.json`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/hiring/pk72_exam_manifest.example.json); раннер: [`tools/hiring/pk72_checker_exam.py`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tools/hiring/pk72_checker_exam.py).

- [ ] До прогона: `freeze` исполнен — файлы работ захешированы, манифест запечатан (sha256-аудит).
- [ ] В манифесте: `role: pk72-exercise-checker`, `pass_k: 0.95`, `series_n: 10`, у каждой работы `expected_verdict` и `expected_rationale`.
- [ ] Прогон раннера по запечатанному манифесту; сырой вывод сохранён рядом с манифестом (датированный файл).
- [ ] Вердикт записан с числами: X/10 верных вердиктов, проходит ли `pass_k` порог; спорная работа → цитата rationale, а не пересказ.
- [ ] После freeze работы не редактировать — правка работы = новый freeze и новый манифест (старый не переиспользовать).

_к.ф.н. М.Ю. Гасунс_
