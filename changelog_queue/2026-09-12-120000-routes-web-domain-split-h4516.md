_Created: 12-09-2026 · Last updated: 12-09-2026_

# H4516: routes/web.php разбит на 12 пер-доменных файлов под routes/web/ (OxAlpha z-ai/glm-5.3-flash, 12-09-2026)

Топ-churn файл репо (1294 строки, 261 роут, 28 замыканий, 136 коммитов с 06-2026) разделён на доменные файлы; точка регистрации не изменилась — `RouteServiceProvider` как и прежде грузит `routes/web.php`, который теперь только подключает домены в фиксированном порядке.

- **Домены** (`routes/web/`): `shop.php` (витрина/чекаут/марафон/уроки курса) · `api-surface.php` (web-группа `/api/games/*`) · `student-public.php` (публичные /koloda, /srs) · `auth.php` (login/register/password) · `content.php` (документы/FAQ/статьи/словарь/чтение/транслит) · `institute.php` (институт+меценаты) · `student.php` (кабинет под auth) · `staff.php` (force-download) · `support.php` (лиды/рассылки/магнитные входы/имперсонация) · `payments.php` (Точка/депозит/триал/PayPal/Bank/счета) · `admin.php` (админские скачивания + редактор лекций) · `public.php` (sitemap/сертификаты/подарки/анкеты/social/партнёры/чат + **catch-all /{slug} — строго последним**).
- **Порядок регистрации сохранён байт-в-байт**: каждый файл — один непрерывный срез старого web.php, loader подключает их в исходном порядке; блоки «строго до catch-all» и «static segment before {slug}» не переставлялись.
- **Гейт параллельности (пас)**: `php artisan route:list --json` до/после — 551 = 551 роутов, множество идентично по domain+method+uri+name+action+middleware, порядок регистрации идентичен; замыканий 28 (счётчик не тронут).
- **Проверки**: `PaidRouteFailClosedTest` 7/7 (деньги fail-closed), полный suite 5538 тестов зелёный, `vendor/bin/pint --dirty` чист (ordered_imports на 2 файлах).
- **Не менялось**: сами определения роутов (байт-в-байт срезы), `routes/api.php` (вебхуки Точки/TG и пр. — там и живут), `RouteServiceProvider`, middleware/флаги.

_Dr. Mārcis Gasūns_
