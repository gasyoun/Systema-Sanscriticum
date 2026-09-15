_Created: 15-09-2026 · Last updated: 15-09-2026_

# H4860: routes/web.php разбит на 12 доменных файлов под routes/web/ — порт H4516 на текущий main (Opus 5 claude-opus-5, 15-09-2026)

Разбиение из [PR #2509](https://github.com/gasyoun/Systema-Sanscriticum/pull/2509) (H4516) перенесено на свежий main: старую ветку нельзя было ни запушить, ни перебазировать, потому что main дописывал монолит, а ветка заменила его скелетом из `require`.

- **Домены** те же, что в H4516: `shop` · `api-surface` · `student-public` · `auth` · `content` · `institute` · `student` · `staff` · `support` · `payments` · `admin` · `public` (catch-all `/{slug}` по-прежнему строго последним).
- **Что main добавил после форка, разнесено**: `/ga/{link}` (короткие ссылки кампаний) и `/online/grammatika-gasuns` (набор в группы грамматики) — в `web/shop.php`, на те же места в порядке регистрации.
- **Припаркованы с TODO**: `/teacher-pay/{tariff}` GET+POST (H4627, флаг `TEACHER_PAY_ENABLED` выключен по умолчанию) лежат в скелете `routes/web.php` сразу после `require web/payments.php`. Их место — внутри `web/payments.php`, но это файл денежного контура, и правка в нём требует подтверждения человека. На сопоставление маршрутов это не влияет: ни один URI в `web/payments.php` не пересекается с `/teacher-pay/{tariff}`.
- **Проверка паритета (пройдена)**: [docs/evidence/h4860/route_parity.py](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/evidence/h4860/route_parity.py). `route:list --json` до и после: 560 = 560 маршрутов, вывод совпадает байт в байт по domain, method, uri, name, action и middleware. Все 26 замыканий, у которых сменился `path`, указывают на ту же исходную строку. По тексту кодовые строки монолита и склеенных срезов совпадают, 674 = 674, кроме одного объявленного блока teacher-pay.
- **Остальные проверки**: `php -l` чист на 13 файлах; `pint --test` пройден; `PaidRouteFailClosedTest`, `TeacherPayTest`, `tests/Feature/Shop` зелёные. Четыре падения в `TrackedLinkTest` и `LeadUtmAttributionTest` воспроизводятся на нетронутом main: это уже существующий разрыв «контроллер против теста» после коммита 2cbb95ba, к маршрутам он отношения не имеет.

_Гасунс_
