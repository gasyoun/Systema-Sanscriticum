# Календарь контента: кросспостинг в Telegram и VK

_Создан 22-10-2026._

Пост планируется в админке (**Маркетинг → Календарь контента → Edit**) и в
назначенное время уходит во все отмеченные площадки:

| Ключ | Площадка | env |
|---|---|---|
| `tg_opoveshchenie` | TG «Оповещение санскритян» | `CONTENT_CAL_TG_OPOVESHCHENIE` (chat_id или @username) |
| `tg_ors` | TG «Общество ревнителей санскрита» | `CONTENT_CAL_TG_ORS` |
| `tg_institut` | TG «Институт исследования санскрита» | `CONTENT_CAL_TG_INSTITUT` |
| `vk_opoveshchenie` | VK samskrte (236973776) | `CONTENT_CAL_VK_OPOVESHCHENIE` |
| `vk_ors` | VK samskrtamru (88831040) | `CONTENT_CAL_VK_ORS` |
| `vk_institut` | VK «Институт исследования санскрита» | `CONTENT_CAL_VK_INSTITUT` |

Площадка с пустым значением выключена и в форме не показывается. Ключи
стабильны: на них завязаны строки `content_calendar_publications`.

## Как это работает

1. `content:publish-due` (ежечасно, флаг `CONTENT_CALENDAR_AUTOPILOT`) берёт
   `scheduled` слоты с наступившим `publish_at`, прогоняет чек-лист §2.8 по
   плоскому тексту и ставит `PublishCalendarSlotJob`.
2. Джоб публикует в каждую выбранную площадку и пишет строку
   `content_calendar_publications` (status / attempts / permalink / error / warning).
   - Telegram — бот `CONTENT_CAL_TG_BOT_TOKEN` (должен быть админом всех трёх
     каналов с правом публикации), `parse_mode=HTML`; с вложением —
     `sendPhoto`/`sendVideo` с подписью.
   - VK — `wall.post` от имени сообщества (`from_group=1`) VK ID токеном админа
     (см. ниже). Группа обязана стоять в `VK_PUBLISH_AUTHORIZED_GROUPS`, иначе
     отказ без повторов.
3. Неудачная площадка остаётся `pending` и повторяется на следующем тике, уже
   опубликованные не трогаются. После 3 попыток (или сразу при отказе гейта /
   нет настроек) — `failed` и сообщение админам в Telegram. Слот становится
   `published`, когда pending-площадок не осталось.

Статус по площадкам — колонка «Площадки» в таблице и блок «Публикации» на
странице слота.

## Форматирование

Редактор: жирный, курсив, подчёркнутый, зачёркнутый, ссылка, цитата,
моноширинный (строка и блок), **маркер = спойлер** в Telegram.

- Telegram получает это как HTML (`App\Services\Content\PostFormatter::toTelegramHtml`).
- **Стена VK форматирование не поддерживает** — уходит простой текст, ссылка
  как «текст (url)». Предпросмотр «Как увидит VK» есть в форме.
- С вложением Telegram допускает подпись до 1024 символов, без — 4096; форма
  не сохранит пост длиннее.

## Вложения

Одна картинка или одно видео, до 50 МБ (предел Bot API), диск `public`,
`storage/app/public/content-calendar/`.

**VK:** вложение уходит, только если у VK-токена есть права `photos` / `video`.
Сейчас (22-10-2026) у токена приложения 54821902 только `wall offline` — пост
в VK уходит текстом, в строке публикации пишется warning, админам приходит
уведомление. Чтобы включить медиа в VK: запросить у поддержки VK доступ
`photos,video` для приложения, переавторизоваться и сделать `vk:token-import`.

## VK-токен

VK ID user-токен живёт **час**; refresh ротирует и `refresh_token` — у пары
может быть только один владелец. Владелец — прод:

- хранится в `vk_user_tokens` (Eloquent `encrypted`);
- `vk:token-refresh` — каждые 15 минут, реально обновляет, когда до
  истечения < 35 мин; первая неудача — сообщение админам;
- `vk:token-import` — принять новый токен из stdin (строки `KEY=VALUE` в
  формате `vk_poll.env`: `VK_APP_ID`, `VK_ACCESS_TOKEN`, `VK_REFRESH_TOKEN`,
  необязательно `VK_DEVICE_ID`). Сразу делает refresh и печатает только
  user_id / scope / срок.

Перенос с Mac (значения нигде не печатаются):

```bash
# 1. выключить Mac-агент, иначе он и прод будут ротировать пару наперегонки
ssh mac 'launchctl bootout gui/$(id -u)/ru.samskrte.vk-token-refresh'
# 2. передать ключи по пайпу
ssh mac 'grep -E "^VK_(APP_ID|ACCESS_TOKEN|REFRESH_TOKEN|DEVICE_ID)=" ~/.claude/secrets/vk_poll.env' \
  | ssh vps-samskrte92 'cd <app> && php artisan vk:token-import'
```

После переноса Mac-скрипты (`vk_poll_crosspost.py`) свежего токена больше не
получают.

Если токен умер (Mac/прод молчали дольше часа, refresh отозван) — заново
авторизоваться (`vk_token_setup.py --flow vkid`) и повторить `vk:token-import`.

## Включение

1. `.env` на проде: `CONTENT_CAL_*`, `CONTENT_CAL_TG_BOT_TOKEN`,
   `VK_PUBLISH_AUTHORIZED_GROUPS` (**прод-сообщества — только после «да» MG на
   каждое**), `config:cache`.
2. `vk:token-import` (см. выше).
3. Смоук: слот с одной площадкой → `php artisan content:publish-due` при
   включённом флаге → проверить пост и строку публикации.
4. `CONTENT_CALENDAR_AUTOPILOT=true`.
