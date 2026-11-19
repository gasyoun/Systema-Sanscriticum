_Created: 17-11-2026 · Last updated: 17-11-2026_

# Чек-лист «Сверка выплат преподавателям по итогам периода» (H8396)

Advisory-чеклист месяц-закрытия: сверка «деньги реально отправлены» ↔ «запись в Systema» ↔ «учётная книга». Не про ЗАПИСЬ выплаты (тот контур — [CHECKLIST_TEACHER_PAYOUT_ROUTE_WALK_H5635](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CHECKLIST_TEACHER_PAYOUT_ROUTE_WALK_H5635_02-10-2026.md) и скилл `teacher-payout-record`), а про сверку после. Составлено 17-11-2026, handoff [H8396](https://github.com/gasyoun/Uprava/blob/main/handoffs/H8396-OxAlpha_Systema-Sanscriticum_checklist-gap-census-top5_17.11.26.md); исполнитель GLM 5.3 Flash (`zai-coding-plan/GLM-5.3-Flash`, OpenCode).

## Входы сверки

| Источник | Что берём | Где |
|---|---|---|
| `teacher_payouts` (prod `.92`) | суммы, учитель, комментарий, timestamps за период | миграция [2026_03_23_212539_create_teacher_payouts_table.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/migrations/2026_03_23_212539_create_teacher_payouts_table.php) |
| Xoom CSV | реально отправленные переводы | `storage/imports/xoom/` |
| Maria-sheet / ИП-книга | учётный контур (книга ИП) | экспорт за тот же период |
| Кабинет бухгалтера | зеркало выплат для сверки глазами | [`ACCOUNTANT_CABINET_GUIDE_RU.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ACCOUNTANT_CABINET_GUIDE_RU.md), гайд [payout-attribution-guide](https://samskrte.ru/admin/payout-attribution-guide) |

## Шаги

1. [ ] Период зафиксирован (календарный месяц; границы в Europe/Moscow).
2. [ ] Выгрузка `teacher_payouts` за период (teacher, amount, type regular/advance, comment, created_at).
3. [ ] Выгрузка Xoom CSV за тот же период; строки нормализованы (дата, получатель, сумма, валюта/курс).
4. [ ] Матчинг 1:1 по учителю + сумме + дате (допуск ±3 дня на межбанковскую задержку).
5. [ ] Три totals совпали: `sum(teacher_payouts)` = `sum(Xoom)` = итог Maria-sheet.
6. [ ] Авансы: FIFO-зачёт с cap не нарушен (маршрут R6 из [чеклиста H5635](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CHECKLIST_TEACHER_PAYOUT_ROUTE_WALK_H5635_02-10-2026.md)); частично погашенные авансы имеют корректный `settled_at` (null только у частичных).
7. [ ] `settlement_key` уникален по периоду (R7 — replay-защита; дубли ключа = P0, останавливает сверку).
8. [ ] Расхождения разобраны по классам (ниже), каждая строка — с владельцем и датой.

## Классы расхождений

- **Деньги отправлены (Xoom), записи в `teacher_payouts` нет** → доучёт через скилл `teacher-payout-record` (Xoom CSV + prod-строка + GTD + хвосты Maria-sheet/ИП-книга); никогда не «пока не записывать».
- **Запись есть, Xoom-строки нет** → проверка: отмена/возврат? Черновик выплаты? Если деньги реально не ушли — статус записи исправить, комментарий с причиной.
- **Суммы разошлись** → сверить курс/комиссии Xoom против записанной суммы; расхождение курса — в комментарий обеих сторон сверки.

## Выход

- [ ] Датированная сверка-записка `docs/PAYOUT_RECON_YYYY-MM.md` (период, totals, таблица матчинга, список расхождений с вердиктами).
- [ ] Незакрытые расхождения — GTD-строка в [GTD_NEXT_ACTIONS.md](https://github.com/gasyoun/Uprava/blob/main/GTD_NEXT_ACTIONS.md) с владельцем; сверка не считается закрытой при открытых money-расхождениях без строки.

_к.ф.н. М.Ю. Гасунс_
