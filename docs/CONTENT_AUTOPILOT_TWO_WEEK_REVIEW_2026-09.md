_Created: 16-09-2026 · Last updated: 16-09-2026_

# Автопилоты контента ВКЛ — двухнедельное пост-хок ревью 16-09 → 30-09-2026 (H5020)

Рулинги MG 16-09-2026 (грилл по цифровому маркетингу, [DECISIONS_DIGITAL_MARKETING_GRILL_16-09-2026.md](https://github.com/gasyoun/Uprava/blob/main/docs/DECISIONS_DIGITAL_MARKETING_GRILL_16-09-2026.md)): **Q7** — список запретов §2.8 ратифицирован как есть; **Q8** — оба флага автопилотов ВКЛ на две недели пост-хок ревью; **Q10** — недельный readout только в понедельничное окно MG; **Q19** — одна фактическая ошибка или один промах в цене / дате эфира выключает соответствующий флаг тем же проходом.

## 1. Что включено на проде (16-09-2026, Fable 5.1 `claude-fable-5-1`)

| Флаг в `/var/www/html/.env` | Было | Стало | Что делает |
|---|---|---|---|
| `TELEGRAM_STORY_PUBLISHER` | `true` (уже стоял до этого прохода) | `true` | `stories:publish-due` ежечасно шлёт approved+due текстовые `story_posts` (lane=channel) в @rusamskrtam магнит-ботом |
| `CONTENT_CALENDAR_AUTOPILOT` | строки не было (default `false`) | `true` | `content:publish-due` ежечасно постит due `scheduled` слоты календаря в VK через n8n-вебхук |

После правки: `php artisan config:clear` + `config:cache`; резервная копия `.env` — `/root/.env.bak-h5020-<timestamp>`. Пробный тик обеих команд сразу после флипа:

```
content:publish-due  → «N8N_CALENDAR_POST_WEBHOOK не настроен — отправка пропущена.»
stories:publish-due  → «publish-due: published=0, skipped_media=0.»  (getChat-проба канала прошла)
```

То есть обе команды **прошли флаг-гейт** (раньше — «flag is OFF — no-op»). Очередь на 16-09: слотов календаря в `scheduled` — 0, `story_posts` approved — 0 (12 черновиков). Публикаций не будет, пока редактор не переведёт черновики в approved / слоты в scheduled.

⚠️ **Календарная полоса пока инертна не из-за флага, а из-за вебхука:** `N8N_CALENDAR_POST_WEBHOOK` / `N8N_CALENDAR_POST_SECRET` в `.env` пусты, n8n-воркфлоу [`docs/n8n/vk-calendar-post.workflow.json`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/n8n/vk-calendar-post.workflow.json) на `.91` не импортирован (шаги 2–3 очереди деплоя №60, отложены с H1965). На n8n-сервере есть живой VK-постер (`xpost-mg — TG → VK`) — токен сообщества можно переиспользовать. Это отдельный `@DO` (GTD Uprava, 16-09-2026).

## 2. Чек-лист перед отправкой — §2.8 в коде

[`config/content_prohibitions.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/content_prohibitions.php) + [`app/Services/Content/ContentProhibitionsChecklist.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Content/ContentProhibitionsChecklist.php). Оба издателя вызывают его **до первого HTTP-вызова**:

| Правило | Что ловит | Действие |
|---|---|---|
| `crm_personal_data` | e-mail, телефон РФ, «ученица Имя Фамилия», «написал мне / в личке», чужой `@хэндл` (не из `allowed_handles`) | **block** |
| `politics_religion_guru` | выборы/госдума/партия/санкции…, гуру / духовный наставник / вероучение / секта… | **block** |
| `competitors` | Окаруто, «лучше/дешевле, чем конкуренты/другие школы» | **block** |
| `unpublished_research` | препринт, неопубликованный, в печати, under review, ARTICLES.md | **block** |
| `price_or_live_date` | сумма в ₽/руб, «19:00 мск», «20 сентября», «тариф #N» | **warn** — публикуется, попадает в дайджест на сверку (контракт §2: цены/даты агент не правит) |

**block** → слот календаря возвращается в `draft` с `meta.prohibition_hold`, `story_post` — в `draft` со строкой `prohibition-hold §2.8: …` в журнале. Пост не уходит и не ретраится молча каждый час — его правит человек и снова ставит в очередь. Списки правятся только в конфиге, не в коде издателей. Пины: `ContentProhibitionsChecklistTest`, `PublishDueContentCommandTest::test_prohibited_slot_is_held_as_draft_and_never_sent`, `StoriesPublishDueTest::prohibited_post_is_held_as_draft_and_never_sent`.

## 3. Понедельничный дайджест

`php artisan content:autopilot-digest` ([команда](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/ContentAutopilotDigestCommand.php)) — по понедельникам 07:00 МСК (Kernel, `content-autopilot-monday-digest`) пишет `storage/app/marketing/autopilot-digest-<дата>.md`: каждый пост автопилотов за неделю (канал, тип слота, ссылка, время МСК, предупреждение чек-листа), всё удержанное §2.8, состояние флагов, пустой блок «Вердикт MG». Опции: `--since`, `--until`, `--out`, `--stdout`.

Доставка: агент понедельничного окна читает файл на проде (`ssh root@193.232.229.92 cat /var/www/html/storage/app/marketing/autopilot-digest-<дата>.md`), кладёт копию в Uprava `docs/marketing/` и вписывает строку в GTD-карточку понедельничного окна. Первый дайджест окна: [Uprava docs/marketing/AUTOPILOT_MONDAY_DIGEST_2026-09-21.md](https://github.com/gasyoun/Uprava/blob/main/docs/marketing/AUTOPILOT_MONDAY_DIGEST_2026-09-21.md) (нулевой — отчёт о флипе).

Ограничение: `TelegramDeliveryChannel::sendMessage()` не возвращает `message_id`, поэтому ссылка у TG-поста ведёт на канал, а не на конкретное сообщение; VK-ссылку даёт n8n и в слоте её нет, если не проставлена в `meta.link`.

## 4. Стоп-правило (Q19) и откат

Одна фактическая ошибка **или** один промах в цене / дате эфира в опубликованном посте → соответствующий флаг в `false` **тем же проходом**, строка в следующем дайджесте, отчёт MG. Окно заканчивается 30-09-2026 рекомендацией keep/revert в дайджесте 05-10 (MG решает).

На прод-сервере (`ssh root@193.232.229.92`, папка `/var/www/html`):

```bash
sed -i 's/^CONTENT_CALENDAR_AUTOPILOT=.*/CONTENT_CALENDAR_AUTOPILOT=false/' .env && php artisan config:cache
```

```bash
sed -i 's/^TELEGRAM_STORY_PUBLISHER=.*/TELEGRAM_STORY_PUBLISHER=false/' .env && php artisan config:cache
```

(~1 мин каждая; команды снова станут no-op на следующем часовом тике; уже опубликованные посты остаются — удаление руками в VK/TG.)

_Гасунс_
