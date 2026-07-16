# Комм-пакет марафона — когорта 28-08-2026

_Created: 17-07-2026 · Last updated: 17-07-2026_

Готовые к вставке русские тексты для первой когорты 3-дневной «Консультации по
онлайн-курсам Общества ревнителей санскрита» (28-08-2026). Пакет — **авторская
заготовка, не публикация**: публикует человек (MG/Иван) по шагам из
[DEPLOY_QUEUE.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md)
и [MARATHON_ACTIVATION_CHECKLIST.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/MARATHON_ACTIVATION_CHECKLIST.md);
агент ничего не деплоит. Составлено Fable 5 (`claude-fable-5`) по
[H1067](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1067-Fable_Systema-Sanscriticum_marathon-cohort-ru-comms-pack_16.07.26.md).

## Состав пакета

| Файл | Что внутри | Куда вставляется |
|---|---|---|
| [marathon-landing-copy-variants.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08/marathon-landing-copy-variants.md) | Два варианта лендинга (A «страхи новичка» / B «результат за 3 дня»): hero, выгоды, FAQ, CTA | Запись `LandingPage` со слагом `konsultaciya-po-onlayn-kursam` через Filament (шаг 2 чек-листа активации) |
| [marathon-email-sequence.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08/marathon-email-sequence.md) | 5 писем: welcome, напоминания Дней 1–3, пост-марафонное письмо с купоном | Будущие Mailable-классы `app/Mail/Marathon*` + шаблоны `resources/views/emails/marathon/` — **черновики**: прод-SMTP сломан ([#504](https://github.com/gasyoun/Systema-Sanscriticum/issues/504)) |
| [marathon-telegram-posts.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08/marathon-telegram-posts.md) | Посты канала @samskrte: анонс, день старта, evergreen-напоминания, пост после эфира | Вставляются вручную в канал; бот-дрип НЕ здесь (см. ниже) |

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
