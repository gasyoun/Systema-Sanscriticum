# Прод: бэкфилл `payments.first_paid_at` выполнен — 8993 платежа, пул повторного приглашения вырос с 4 до 696 (H5410, Fable 5.1 `claude-fable-5-1`, 24-09-2026)

По решению MG («делай backfill», 24-09-2026) на проде запущена уже существовавшая команда `payments:backfill-first-paid-at --apply` (H1645): 8993 строки `NULL → дата` (из `payment_audits`, иначе `created_at`), 173 неоплаченных без следа остались NULL. Снимок id до записи — `storage/app/backfill_first_paid_at_null_ids_20260924.json` (откат одним UPDATE). Широкое окно `students:reinvite-48h --lookback-days=3650` теперь видит 696 человек (первый батч 100: 73 email); рассылка не запускалась. Детали: [`RESULTS_REINVITE_48H_PAID_NEVER_LOGIN_H5022_17-09-2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RESULTS_REINVITE_48H_PAID_NEVER_LOGIN_H5022_17-09-2026.md). Кода нет.

_Гасунс_
