_Created: 17-11-2026 · Last updated: 17-11-2026_

# Чек-лист «Приёмка домашней работы» — QC-гейт перед сменой статуса (H8396)

Advisory-чеклист (не гейт): единое ядро проверки для любого проверяющего — MG вручную в кабинете/Filament или агент-ассистент (R14: «both loops, one gate»). Составлено в ходе переписи чек-листов имения, handoff [H8396](https://github.com/gasyoun/Uprava/blob/main/handoffs/H8396-OxAlpha_Systema-Sanscriticum_checklist-gap-census-top5_17.11.26.md); исполнитель GLM 5.3 Flash (`zai-coding-plan/GLM-5.3-Flash`, OpenCode).

## Контур (проверено по коду, origin/main 17-11-2026)

- Статусы [`HomeworkSubmission`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/HomeworkSubmission.php): `draft` → `submitted` → `needs_revision` → `accepted`; факт приёмки фиксируют `reviewed_by` / `reviewed_at`.
- Сдача: `POST /c/{slug}/u/{lessonId}/homework` → [`HomeworkController::store`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/HomeworkController.php) → [`HomeworkService::recordSubmission`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/HomeworkService.php) создаёт submission + comment + files, ставит PDF в очередь, уведомляет учителя (`HomeworkNotifier::submitted`).
- Данные сдачи: `user_id`, `lesson_id`, `course_id`, `status`, `body`, `files[]`, `comments[]` (текст + файлы), связи `lesson`/`course`.

## Ядро QC — пройти до перевода статуса (любой проверяющий)

1. [ ] Открыта именно та сдача: `lesson_id`/`course_id` совпадают с уроком, к которому задание (не соседняя группа/урок).
2. [ ] Все файлы открываются и рендерятся (PDF дошёл из очереди; битые/нулевые файлы — вернуть `needs_revision`, а не молча пропустить).
3. [ ] Прочитан и текст `body`, и файлы — вердикт не по одному источнику.
4. [ ] Вердикт сверён с заданием урока (что именно было задано), а не с общим впечатлением старательности.
5. [ ] `needs_revision` несёт конкретный список правок (что → как исправить), иначе ученик не сможет исправить.
6. [ ] `accepted` сопровождается коротким обоснованием (1–2 строки: что подтверждено).
7. [ ] Уведомление ученику ушло (`HomeworkNotifier`) — студент видит вердикт в кабинете.
8. [ ] `reviewed_by`/`reviewed_at` заполнились автоматически — если пусто, приёмка шла мимо штатного экрана: остановиться и разобраться.

## Санскрит-специфика

- [ ] Сначала опознаны целевые формы урока (тема урока → какие формы ожидаются), только потом гипотезы об ошибках — правило «fit first, errata second» из плейбука разбора ДЗ ([KocherginaUchebnik_1998/PLAYBOOK_KOCHERGINA_*.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/KocherginaUchebnik_1998) и скилл `homework-parse-playbook`).
- [ ] Sandhi/транслитерация в ответе ученика сверены по правилам текущего урока, не по интуиции.
- [ ] Кандидат-опечатка учебника: вердикт только по правилу «свидетель» (kind: print | digitization); нет свидетеля — кандидат паркуется, никогда не фейковый вердикт.

## Agent-assist (доверие машине)

Агентские вердикты — advisory, авто-приёмки нет (R5/R14). Агент обязан показать проверяющему:

1. Разбор ответа ученика (пословно/поформенно).
2. Сопоставление с целевыми формами урока + указатель на корпусное подтверждение (attestation), не «мне так кажется».
3. Список расхождений «ожидание vs ответ» с классификацией (ошибка ученика / опечатка учебника-кандидат / неоднозначность).
4. Проверяющий спот-чекит ≥2 пункта вручную до доверия вердикту; при первом же несовпадении спот-чека — полный ручной прогон этой сдачи.

## Ссылки

- Скилл-ранбук разбора ДЗ: `homework-parse-playbook` (двойник в [`KocherginaUchebnik_1998`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/KocherginaUchebnik_1998)).
- Учёт выплат/ЗП преподавателя — не здесь: [CHECKLIST_TEACHER_PAYOUT_ROUTE_WALK_H5635_02-10-2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CHECKLIST_TEACHER_PAYOUT_ROUTE_WALK_H5635_02-10-2026.md).

_к.ф.н. М.Ю. Гасунс_
