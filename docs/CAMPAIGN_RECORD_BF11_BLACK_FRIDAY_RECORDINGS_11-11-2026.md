# Журнал кампании bf11 — ЧП-промо «шесть записей» (−50%)

_Created: 11-11-2026 · Last updated: 11-11-2026_

Кампания атрибуции H8045: промо-пост «шесть записей со скидкой до −50%» (Чёрная пятница) в канале @samskrte 11-11-2026 со зеркальным репостом в @samskrtamru, окно скидки 11-11→30-11-2026 включительно. Решения грилла — [DECISIONS_BF_RECORDINGS_PROMO_GRILL_11-11-2026.md](https://github.com/gasyoun/Uprava/blob/main/docs/DECISIONS_BF_RECORDINGS_PROMO_GRILL_11-11-2026.md) (Q1 UTM-пакет обязателен, Q11 оба канала, Q15/Q17), черновик поста — [content/queue/2026-11-11-BF-ANN.md](https://github.com/gasyoun/Uprava/blob/main/content/queue/2026-11-11-BF-ANN.md) (Uprava PR [#7370](https://github.com/gasyoun/Uprava/pull/7370)). Словарь меток — [GRAMMAR_GASUNS_UTM_STANDARD_12-09-2026.md](https://github.com/gasyoun/Uprava/blob/main/docs/GRAMMAR_GASUNS_UTM_STANDARD_12-09-2026.md), механизм — [TrackedLinkController.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/TrackedLinkController.php), ключи — [config/tracked_links.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/tracked_links.php). `Kind` = **обзорное** (журнал: `anons:ops journal-add --campaign=bf11 --kind=обзорное`). 152-ФЗ: только агрегаты, без PII.

Видимая ссылка поста — только `/ga/`-ключ под человеческим анкором; сырой UTM в текст не идёт. Репост @samskrtamru не переиспользует ors-ключи: стандарт требует различать источником переходы разных площадок, поэтому у зеркала свой набор `bf11-samskrtam-*` (токен `samskrtam` / `crosslink`).

## Готовые ссылки — основной пост @samskrte

| Размещение | Креатив | Ссылка |
|---|---|---|
| telegram_samskrte / owned_channel | BF11-ZACH ↔ `bf11_zach` | [bf11_zach](https://samskrte.ru/ga/bf11-ors-zach) |
| telegram_samskrte / owned_channel | BF11-ASTR ↔ `bf11_astr` | [bf11_astr](https://samskrte.ru/ga/bf11-ors-astr) |
| telegram_samskrte / owned_channel | BF11-MOL ↔ `bf11_mol` | [bf11_mol](https://samskrte.ru/ga/bf11-ors-mol) |
| telegram_samskrte / owned_channel | BF11-HAN ↔ `bf11_han` | [bf11_han](https://samskrte.ru/ga/bf11-ors-han) |
| telegram_samskrte / owned_channel | BF11-CAL ↔ `bf11_cal` | [bf11_cal](https://samskrte.ru/ga/bf11-ors-cal) |
| telegram_samskrte / owned_channel | BF11-VED ↔ `bf11_ved` | [bf11_ved](https://samskrte.ru/ga/bf11-ors-ved) |
| telegram_samskrte / owned_channel | BF11-P ↔ `bf11_p` | [bf11_p](https://samskrte.ru/ga/bf11-ors-p) |

## Готовые ссылки — зеркальный репост @samskrtamru

| Размещение | Креатив | Ссылка |
|---|---|---|
| samskrtam / crosslink | BF11-ZACH ↔ `bf11_zach` | [bf11_zach](https://samskrte.ru/ga/bf11-samskrtam-zach) |
| samskrtam / crosslink | BF11-ASTR ↔ `bf11_astr` | [bf11_astr](https://samskrte.ru/ga/bf11-samskrtam-astr) |
| samskrtam / crosslink | BF11-MOL ↔ `bf11_mol` | [bf11_mol](https://samskrte.ru/ga/bf11-samskrtam-mol) |
| samskrtam / crosslink | BF11-HAN ↔ `bf11_han` | [bf11_han](https://samskrte.ru/ga/bf11-samskrtam-han) |
| samskrtam / crosslink | BF11-CAL ↔ `bf11_cal` | [bf11_cal](https://samskrte.ru/ga/bf11-samskrtam-cal) |
| samskrtam / crosslink | BF11-VED ↔ `bf11_ved` | [bf11_ved](https://samskrte.ru/ga/bf11-samskrtam-ved) |
| samskrtam / crosslink | BF11-P ↔ `bf11_p` | [bf11_p](https://samskrte.ru/ga/bf11-samskrtam-p) |

Соответствие курсов: `zach` → «Благоприятные зачины» (`NOV50-ZACH`), `astr` → «Астрономия для астрологов» (`NOV50-ASTR`), `mol` → «Ежедневные молитвы» (`NOV50-MOL`), `han` → «Хануман Чалиса» (`NOV50-HAN`), `cal` → «Восточные календари» (`NOV50-CAL`), `ved` → «Ликбез по веданте» (`NOV50-VED`), `p` → закрывающий CTA, каталог `/online` (промокода не несёт — коды лежат рядом с шестью курсовыми ссылками в тексте).

## Строка размещения 1 — @samskrte (campaign-record-template, /anons)

| Field | Value |
|---|---|
| Campaign ID | `bf11` — `black_friday_recordings_nov_2026` |
| Kind (вид кампании) | обзорное |
| Creative ID | `BF11-ZACH` ↔ `bf11_zach`; `BF11-ASTR` ↔ `bf11_astr`; `BF11-MOL` ↔ `bf11_mol`; `BF11-HAN` ↔ `bf11_han`; `BF11-CAL` ↔ `bf11_cal`; `BF11-VED` ↔ `bf11_ved`; `BF11-P` ↔ `bf11_p` |
| Text/version ID | `bf11-post-v1` (крючок A/B/C — на выбор MG, Q16) |
| Placement | Telegram @samskrte, промо-пост «шесть записей» |
| Account or channel | `ors`: `telegram_samskrte` / `owned_channel` |
| Publication time and zone | pending — выставить при публикации (МСК; t.me/s/ показывает UTC, +3ч) |
| Post permalink / message_id | pending до публикации |
| VK poll/post IDs and permalinks | неприменимо |
| Media master / published file | неприменимо: текстовый пост (пост несёт только `/ga/`-ссылки) |
| Final copy location | [Uprava content/queue/2026-11-11-BF-ANN.md](https://github.com/gasyoun/Uprava/blob/main/content/queue/2026-11-11-BF-ANN.md) (PR [#7370](https://github.com/gasyoun/Uprava/pull/7370)) |
| Reader-facing short link | https://samskrte.ru/ga/bf11-ors-{zach,astr,mol,han,cal,ved,p} |
| UTM source / medium / campaign / content / term | `telegram_samskrte` / `owned_channel` / `black_friday_recordings_nov_2026` / `bf11_*` / `recordings` |
| Cost and currency | 0 ₽, owned placement |
| Paid, free partner post, or owned placement | собственный канал |
| Response owner | куратор: @rusamskrtam |
| 24h views / clicks / inquiries | снять через 24ч после публикации: `php artisan anons:ops metrics` (`anons_link_clicks`, агрегаты) |
| 72h views / clicks / inquiries | снять через 72ч после публикации |
| Suitable leads / payments / revenue | оплаты — промокоды `NOV50-*` (course_id 319/345/308/376/367/337, expiry 30-11-2026); не приписывать покупку одному клику без наблюдаемой связи |
| Reported source for unattributed leads | `reported_source` отдельно; UTM не выдумывать |
| Findings and next action | ключи заминтованы; после публикации — `journal-fill --link=…` с permalink/временем (вместо message_id pending), затем метрики 24h/72h |

## Строка размещения 2 — репост @samskrtamru

| Field | Value |
|---|---|
| Campaign ID | `bf11` — `black_friday_recordings_nov_2026` |
| Kind (вид кампании) | обзорное |
| Creative ID | тот же набор `BF11-*` ↔ `bf11_*` (источник различает площадку) |
| Text/version ID | `bf11-repost-v1` (зеркало основного поста, Q5) |
| Placement | Telegram @samskrtamru, репост после основного поста (не второй sales подряд — GTD-остаток YBC) |
| Account or channel | `samskrtam`: `samskrtam` / `crosslink` |
| Publication time and zone | pending |
| Post permalink / message_id | pending до публикации |
| Reader-facing short link | https://samskrte.ru/ga/bf11-samskrtam-{zach,astr,mol,han,cal,ved,p} |
| UTM source / medium / campaign / content / term | `samskrtam` / `crosslink` / `black_friday_recordings_nov_2026` / `bf11_*` / `recordings` |
| Cost and currency | 0 ₽, owned placement |
| Response owner | куратор: @rusamskrtam |
| 24h / 72h views / clicks / inquiries | как у строки 1, от публикации репоста |
| Findings and next action | при публикации — сверить, что в репосте стоят `bf11-samskrtam-*`, а не ors-ключи (смешение источников = дефект атрибуции) |

## Премортем перед выпуском

- **Ключ ещё 404 / ведёт не на ту страницу.** Ранний сигнал: bounded smoke (≤3 попытки) не даёт 302 на ожидаемый `/k/` и 200 на цели. Точка невозврата — отправка поста; до неё публикацию не производить, читать `tail -3 storage/logs/deploys.log` на проде.
- **Деплой не поднялся (dirty-gate / auto-deploy ≤30 мин).** Ранний сигнал: после merge `git rev-parse HEAD` на проде ≠ `origin/main`, ключи 404. Действие: `ssh root@193.232.229.92 'sudo /bin/bash /var/www/html/deploy.sh'`; не править config на VPS руками.
- **Репост несёт ors-ключи.** Ранний сигнал: в @samskrtamru клики приходят с `utm_source=telegram_samskrte`. Действие до отправки репоста: сверить ссылки с таблицей репоста; смешение источников не лечится постфактум.
- **Дубль поста / коллизия каденции.** Ранний сигнал: свежая лента @samskrte уже содержит промо (разовое исключение Q12). Проверить ленту перед отправкой.
- **Отправка прошла, подтверждение потеряно.** Точка невозврата — `sendMessage`; перед повтором сверить публичную ленту и сохранить permalink → `journal-fill`.

_к.ф.н. М.Ю. Гасунс_
