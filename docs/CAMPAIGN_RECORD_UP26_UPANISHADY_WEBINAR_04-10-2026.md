# Журнал кампании up26 — вебинар «Традиции толкования упанишад»

_Created: 04-10-2026 · Last updated: 04-10-2026_

Первая реальная кампания атрибуции скилла anons-attribution-mint (H6078): вводное бесплатное занятие курса 05-10-2026 18:00 МСК, Иван Толчельников. Словарь меток — [GRAMMAR_GASUNS_UTM_STANDARD_12-09-2026.md](https://github.com/gasyoun/Uprava/blob/main/docs/GRAMMAR_GASUNS_UTM_STANDARD_12-09-2026.md), механизм — [TrackedLinkController.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/TrackedLinkController.php), ключи — [config/tracked_links.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/tracked_links.php). 152-ФЗ: только агрегаты, без PII.

## Готовые ссылки

| Размещение | Креатив | Ссылка |
|---|---|---|
| Пост канала @samskrte — запись на эфир | U26-C | https://samskrte.ru/ga/up26-ors-c |
| Пост канала @samskrte — программа и оплата | U26-P | https://samskrte.ru/ga/up26-ors-p |

Пост несёт только `/ga/`-ключ под человеческой анкор-фразой; сырой UTM в текст не идёт (метки остаются в сессии, форма лендинга подхватывает их скрытыми полями).

## Строка размещения (campaign-record-template, /anons)

| Field | Value |
|---|---|
| Campaign ID | `up26` — `upanishady_webinar_oct_2026` |
| Creative ID | `U26-C` ↔ `u26_c` (запись на эфир); `U26-P` ↔ `u26_p` (оплата) |
| Text/version ID | пост-анонс v1 |
| Placement | Telegram-канал @samskrte (Общество ревнителей санскрита), дополнение к посту 631 |
| Account or channel | `ors`: `telegram_samskrte` / `owned_channel` |
| Publication time and zone | вносится после публикации поста (МСК) |
| Post permalink | `t.me/samskrte/<номер>` после публикации |
| VK poll/post IDs | — (не VK) |
| Media master / published file | — (текстовый пост) |
| Orientation and dimensions | — |
| Final copy location | пост канала; черновик фрагмента — чат ZCode 04-10-2026 |
| Reader-facing short link | https://samskrte.ru/ga/up26-ors-c · https://samskrte.ru/ga/up26-ors-p |
| UTM source / medium / campaign / content / term | `telegram_samskrte` / `owned_channel` / `upanishady_webinar_oct_2026` / `u26_c`, `u26_p` / `philosophy` |
| Cost and currency | 0, owned placement |
| Paid, free partner post, or owned | owned |
| Response owner | куратор, t.me/rusamskrtam |
| 24h / 72h views / clicks / inquiries | после публикации: `php artisan anons:ops metrics` (таблица `anons_link_clicks` — slug + UTM + время) |
| Suitable leads / payments / revenue | лиды лендинга `/webinar-upanishady-2026`; оплаты — чекауты `/checkout/5104–5107` |
| Reported source for unattributed leads | `reported_source`, UTM не выдумывать |
| Findings and next action | ссылку на эфир раздаёт письмо шага `webinar_invite` (`landing.webinar_url`); после публикации — дописать permalink/время и снять метрики 24/72h |

_Гасунс_
