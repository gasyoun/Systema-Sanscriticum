# Комм-пакет марафона — когорта 28-08-2026

_Created: 17-07-2026 · Last updated: 31-07-2026_

Готовые русские тексты для первой когорты 3-дневной «Консультации по
онлайн-курсам Общества ревнителей санскрита» (28-08-2026). Авторство H1067
(Fable 5 `claude-fable-5`, [PR #544](https://github.com/gasyoun/Systema-Sanscriticum/pull/544)).

**Публикация (28-07-2026, Grok 4.5 `grok-4.5`):** вариант **A** вшит в
`/online/konsultaciya` (config `marathon_landing_copy`, default `a`); **B** —
второй этап через `MARATHON_LANDING_COPY_VARIANT=b` + `config:clear`.

```bash
# 1) LandingPage-строка (Filament/slug) = вариант A, затем B
php artisan marathon:apply-landing-copy a
# позже: php artisan marathon:apply-landing-copy b
#        + MARATHON_LANDING_COPY_VARIANT=b && php artisan config:clear

# 2) TG-посты канала @samskrte (магнит-бот из Marketing Settings)
php artisan marathon:publish-channel-posts --post=1          # dry-run
php artisan marathon:publish-channel-posts --post=1 --live   # реальная отправка
```

Бот для канала: **тот же магнит-бот** (`MarketingSetting.tg_bot_token` /
`tg_bot_username`, обычно `@samskrte`) — он уже гоняет Day 1–3 drip. Ему
нужны права **администратора канала** @samskrte с «Публикация сообщений».
Не student-bot, не zapisi-bot.

## Состав пакета

| Файл | Что внутри | Куда вставляется |
|---|---|---|
| [marathon-landing-copy-variants.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08/marathon-landing-copy-variants.md) | Два варианта лендинга (A «страхи новичка» / B «результат за 3 дня»): hero, выгоды, FAQ, CTA | Запись `LandingPage` со слагом `konsultaciya-po-onlayn-kursam` через Filament (шаг 2 чек-листа активации) |
| [marathon-email-sequence.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08/marathon-email-sequence.md) | 5 писем: welcome, напоминания Дней 1–3, пост-марафонное письмо с купоном | Mailable-классы `app/Mail/Marathon*` + шаблоны (H1148); прод SMTP **живой** (Yandex, `mail:preflight` OK 31-07 H2014) — bulk send всё ещё human-gated; [#504](https://github.com/gasyoun/Systema-Sanscriticum/issues/504) «mailpit» root-cause устарел |
| [marathon-telegram-posts.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08/marathon-telegram-posts.md) | Посты канала @samskrte: анонс, день старта, evergreen-напоминания, пост после эфира | Вставляются вручную в канал; бот-дрип НЕ здесь (см. ниже) |
| [KONSULTACIYA_REDESIGN_DIRECTIONS_30.07.26.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08/KONSULTACIYA_REDESIGN_DIRECTIONS_30.07.26.md) | H1966: 4 visual directions A–D; **multi-dir** (no single pick; B = default) | Concurrent visual variants on implement — **not** live CSS yet |
| [redesign/](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/marketing/marathon-2026-08/redesign) | Static HTML mockups A–D + `/useit` Nielsen pass | Open HTML in browser; design packet |

## Что каноническое ГДЕ — не дублировать

- **Бот-дрип Дней 1–3 и теплый хвост Дней 4–16** уже написаны и живут в
  [config/marathon.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/marathon.php)
  (`day1_message`/`day2_message`/`day3_message_*`/`warm_tail_messages`) — это
  рабочий код, рассылаемый `marathon:deliver-due`/`marathon:deliver-warm-tail`.
  Файлы этого пакета их **не переписывают**; письма и посты ссылаются на те же
  дни, но текст свой.
- **Строки страниц Дней 1–2 и текущий hero лендинга** — в
  [resources/views/marathon/](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/resources/views/marathon):
  вариант B лендинга сознательно опирается на существующий hero, не заменяя его.
- **Дизайн-решения и тон** — [Uprava/custdev/MARATHON_DIAGNOSTIC_2026.md](https://github.com/gasyoun/Uprava/blob/main/custdev/MARATHON_DIAGNOSTIC_2026.md):
  антисрочность (никаких дедлайнов, «мест осталось», обратных отсчетов), «~15
  минут в день», тон «сакральная серьезность, не инфо-цыганский движ», рычаги
  время → рассрочка → преподаватель → один точечный отзыв.

## Жесткие правила пакета (из дизайн-дока)

1. **Отзыв никогда не выдумывается.** Везде, где стоит `{testimonial}`, текст
   публикуется только после того, как MG внесет реальную цитату
   (`MARATHON_TESTIMONIAL` в `.env`); до этого блок опускается целиком.
2. **Ни одного элемента срочности** в продающем контуре: без дат-дедлайнов
   купона, без «успейте», без счетчиков. Дата 28-08-2026 упоминается только как
   факт старта первой когорты.
3. Обращение — «вы» со строчной; в длинных текстах (лендинг, письма) — без
   эмодзи; в Telegram-постах — не больше одного эмодзи на пост (стиль
   существующего бот-дрипа).
4. Кириллица, без деванагари (когорта `zero`, H440 §1a).

_Dr. Mārcis Gasūns_
