# Копи-первоисточник: лендинг «Основы цифровой грамотности»

_Created: 24-08-2026 · Last updated: 24-08-2026_

**Видимость:** приватный хаб [gasyoun/Systema-Sanscriticum](https://github.com/gasyoun/Systema-Sanscriticum) (`PRIVATE`).

**Назначение:** канонический текст лендинга `/online/cifrovaya-gramotnost` ([resources/views/shop/diglit.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/shop/diglit.blade.php)). Blade и этот документ правятся синхронно; формулировки исследований менять нельзя без повторной сверки с первоисточниками.

## Сверенные цифры (verify 24-08-2026, первоисточники)

1. **PwC Global AI Jobs Barometer 2025** — [пресс-релиз PwC, 03-06-2025](https://www.pwc.com/gx/en/news-room/press-releases/2025/ai-linked-to-a-fourfold-increase-in-productivity-growth.html): «AI-skilled workers see average **56% wage premium in 2024**, double the **25%** in the previous year». База: почти миллиард вакансий. ✅ CONFIRMED дословно.
2. **Google × Ipsos** — [Fortune, 19-02-2026](https://fortune.com/2026/02/19/exclusive-google-ipsos-report-five-percent-of-workers-ai-fluent-raises-promotions-workforce-advantage-gen-z-advice/): лишь **5%** работников США «AI fluent» (перестроили значимые части своей работы); они **в 4,5 раза чаще** сообщают о более высокой зарплате и **в 4 раза чаще** о повышении — сравнение с теми, кто на ранней стадии освоения ИИ. ✅ CONFIRMED с обязательной оговоркой базы сравнения («чем те, кто ещё на ранней стадии»).

## Жёсткие правила формулировок

1. Позиционирование — **«уверенная работа с нейросетями»**, не «цифровая грамотность» (категория воюет с бесплатным госсектором: «Азбука интернета», «Московское долголетие», «Цифровые профессии»).
2. Никаких обещаний дохода («гарантируем заработок» запрещено); цифры — только как рыночный контекст со ссылками.
3. Корочки ДПО нет: в FAQ вопрос называется и прямо отвергается («Нет. Выдаём собственный сертификат школы.»). Не переформулировать в сторону обещания удостоверения.
4. Записи остаются навсегда; проверка домашних заданий — на время потока (это честное отличие от тарифа «Записи + чат»).
5. Дефицит честный и статичный: «ранние птицы — первым десяти записавшимся или до 15 сентября»; динамические счётчики мест на странице не выводятся (правило money-copy: no fake scarcity).
6. Рассрочка на странице не обещается (в чекауте не подтверждена) — только «вопросы по рассрочке решаются в чате набора».

## Тарифы (ратифицированная лестница MG 24-08-2026)

| Тариф | Цена | type | is_recording | is_active при создании |
|---|---|---|---|---|
| Ранние птицы | 14 900 ₽ | full | false | true (квота «10 мест / до 15-09» — копирайтом) |
| Основной | 19 900 ₽ | full | false | true |
| VIP-группа | 34 900 ₽ | full | false | true |
| Записи + чат | 8 900 ₽ | full | true | **false** — открывается после набора основного |

Курс: slug `diglit-2026`, `is_visible=false`, lessons_count 16, hours_count 24. Страница инертна до включения `DIGLIT_LANDING=true` ([config/features.php → diglit_landing](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/features.php)).

## Связанные документы

- Ценовое досье: [Uprava docs/COURSE_DIGLIT_PRICING_RU_MARKET_24-08-2026.md](https://github.com/gasyoun/Uprava/blob/main/docs/COURSE_DIGLIT_PRICING_RU_MARKET_24-08-2026.md)
