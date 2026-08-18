# Запрещенные приемы в живом контуре — аудит срочности и соцдоказательства

_Created: 19-08-2026 · Last updated: 19-08-2026_

Handoff [H3022](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3022-Opus_Systema-Sanscriticum_revenue-copy-playbook-conformance-audit_17.08.26.md).
Исполнитель: Opus 5 (`claude-opus-5`), 19-08-2026.

## Что проверялось

Два приема, которые плейбук возражений (приватный, Uprava, Pages выключены) называет
отталкивающими, а голосовой контракт §1 «Never» запрещает дословно:

1. **искусственная срочность** — «Спешите», «Успейте», «Только сегодня», тикающие
   счетчики, «осталось мало мест»;
2. **соцдоказательство навалом** — «у нас уже сотни учеников», «все берут этот курс»,
   рамка «присоединяйтесь к N».

Постановление D4 от 19-07-2026
([money-honest-scarcity-urgency-rewrite.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/money-honest-scarcity-urgency-rewrite.md))
закрыло **только два блока** —
[price_block.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/promo/blocks/price_block.blade.php)
и [course_streams_block.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/promo/blocks/course_streams_block.blade.php)
— и **только срочность**. Соцдоказательство не проверялось никогда, копия ботов не
проверялась никогда. Это и есть предмет этого прохода.

## Главный вывод: код починен, данные — нет

Правка D4 убрала выдуманные дефолты из Filament-схемы, но **не тронула строки, уже
сохраненные с этими дефолтами**. Проверка прод-БД (read-only `SELECT` по
`landing_pages`, 19-08-2026, 9 активных страниц) показывает, что фальшивый дефицит жив
в данных:

| Слаг | Поле | Значение в проде | Что видит человек |
|---|---|---|---|
| `webinar-grammatika-17_06_26` | `seats_taken` / `seats_total` | **16 / 20** | «Свободных мест: 4 из 20» — ровно тот выдуманный дефолт, который D4 удалил из схемы |
| `sans` | `seats_taken` / `seats_total` | 15 / 20 | «Свободных мест: 5 из 20» — числа июньские, с тех пор не обновлялись |
| `hindi` | `seats_taken` / `seats_total` | 5 / 8 | счетчик мест из апрельской настройки |
| `sans`, `hindi`, `webinar-grammatika-17_06_26` | `timer_end` | 17.06 / 21.04 / 21.06 | **не рендерится** — истекший дедлайн скрывается кодом D4 ✅ |

Код ведет себя честно ровно там, где может: истекший `timer_end` он распознает и
прячет. Но у мест нет даты — валидатор
([price_block.blade.php:36–46](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/promo/blocks/price_block.blade.php))
проверяет только `0 ≤ занято ≤ всего`, поэтому числа двухмесячной давности проходят
как «реальные данные». **Гарантию D4 «дефицит только с реальными данными» держит
дисциплина менеджера, а не код.**

Это то, чего проход 19-07-2026 увидеть не мог: он был grep'ом по репозиторию, а строки
живут в БД.

## Найденное в репозитории

| # | Файл:строка | Прием | Вердикт |
|---|---|---|---|
| 1 | [newsletter-subscribe-popup.blade.php:195](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/partials/newsletter-subscribe-popup.blade.php) | «Присоединяйтесь к **5 000+** русскоязычным студентам санскрита» — рамка «иди за толпой» | **kill** — переписано |
| 2 | [newsletter-subscribe-popup.blade.php:19](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/partials/newsletter-subscribe-popup.blade.php) | `config('trust.graduates_count') ?: 5000` — жестко зашитый фолбэк поверх env-контракта | **kill** — число ушло из блока вместе с формулировкой |
| 3 | [LandingPageResource.php:147](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Resources/LandingPageResource.php) | подсказка поля учит менеджера писать «*17 июня* — последний шанс» | **kill** — админка не должна диктовать запрещенный прием |
| 4 | [new_hero_with_form.blade.php:151–158](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/promo/blocks/new_hero_with_form.blade.php) | плашка «Осталось N дней» с пульсирующей точкой `animate-ping` | **kill театра, счет оставлен** — дата вебинара реальная, тогл по умолчанию выключен; убрана только анимация |
| 5 | [resources/views/README.md:56](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/README.md) | строка про `countdown_block.blade.php` — «Таймер обратного отсчета» | **kill строки** — файла нет в репозитории с D4 |
| 6 | [main.blade.php:9](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/main.blade.php) | meta description: «21+ лет сообществу, 5 000+ учеников» | **already-compliant** — это конкретный факт в SEO-описании, а не давление толпой; но число зашито мимо `config/trust.php` |
| 7 | [proof-block.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/partials/proof-block.blade.php), [trust-strip.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/shop/partials/trust-strip.blade.php) | плитки «N+ учеников — столько человек прошло наши курсы» | **already-compliant** — утверждение факта, а не «присоединяйтесь к толпе»; обе плитки гаснут при пустом `graduates_count` |
| 8 | [ladder.blade.php:10](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/shop/partials/ladder.blade.php) | «присоединяйтесь к живому потоку, когда будете готовы» | **already-compliant** — «когда будете готовы» снимает давление, а не создает его |
| 9 | [marathon/skins/{a,b,c}/content.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/resources/views/marathon/skins) | явный анти-urgency герой (H1067): нет отсчета, нет «мест», вход в любой день | **already-compliant** |

### Где проходит граница между 6–7 и 1

Разница не в числе, а в грамматике призыва. «5 000+ учеников прошли наши курсы» —
проверяемое утверждение о прошлом, читатель волен им не интересоваться.
«**Присоединяйтесь** к 5 000+ студентам» — императив, аргументом которого служит
размер толпы; ровно этот прием плейбук ставит в самый низ таблицы. Поэтому плитки
доверия остались, а строка поп-апа переписана в пользу читателя:

> Анонсы курсов, открытые занятия и бесплатные материалы — на почту. Отписка в один клик.

Запрет закреплён тестом, а не памятью следующего автора копии: `test_floating_popup_renders_without_bulk_social_proof` в [NewsletterSubscribeTest.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/NewsletterSubscribeTest.php) проверяет новую формулировку **и** `assertDontSee` на старую рамку толпы.

## Копия ботов — чисто, и не случайно

Это была заявленная зона риска handoff'а: проход 19-07-2026 ботов не смотрел.

- [BotKnowledgeBase.php:99–100](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Bot/BotKnowledgeBase.php)
  — системный промпт студенческого бота **запрещает оба приема дословно**: «не создавай
  искусственную срочность («осталось N мест», «успей до…», «цена растет»)» и «не дави
  социальным доказательством («у нас уже N учеников», «все берут этот курс»)». Совпадение
  с плейбуком не приблизительное, а буквальное.
- Жестко зашитые исходящие строки ботов
  ([TelegramWebhookController.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/TelegramWebhookController.php),
  [ProcessVkBotMessage.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Jobs/ProcessVkBotMessage.php),
  [TelegramMagnetWebhookController.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/Webhooks/TelegramMagnetWebhookController.php))
  — транзакционные целиком: привязка аккаунта, отписка, передача куратору, «Изучаю
  манускрипты». Ни одного продающего приема, ни одного числа.
- Шаблон напоминания о занятии в `marketing_settings.zapisi_reminder_template` (прод,
  прочитан 19-08-2026) — «Занятие «{title}» начнется сегодня в {time}», ссылка. Чисто.
- Все четыре колонки `debt_reminder*_text` в проде **пусты** — долговые письма идут из
  кода, а не из БД, то есть попадают под обычный ревью репозитория.

## Уже существующий рычаг, который недотянут

[VoiceContractLinter.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Content/VoiceContractLinter.php)
— детерминированный гейт запрещенных фраз, который уже работает. Но:

- он проверяет **только** копию для стены ВК, отполированную CuratorAi (H1791), и никогда
  не касается blade-шаблонов, копии ботов и `MarketingSetting`;
- в его списке `BANNED_PHRASES` есть «спешите», «успейте», «не упустите» — и **ни одной
  формулировки соцдоказательства**. «Присоединяйтесь к 5 000+ студентам» прошло бы линтер
  насквозь.

Дешевый следующий шаг — не новый инструмент, а два действия над существующим: добавить
в таблицу рамку «присоединяйтесь к N» и подключить линтер к тесту, который читает
blade-строки продающего контура.

## Что остается человеку

1. **Прод-данные мест.** `webinar-grammatika-17_06_26` = 16/20 и `sans` = 15/20 — это
   июньские числа, а 16/20 — след удаленного дефолта. Обнулить (спрятать счетчик) или
   вписать сегодняшние — решение о денежном контуре и о том, что считается правдой на
   витрине; агент такое не пишет в прод.
2. **`config/trust.php`.** Комментарий в шапке файла говорит «`graduates_count = null` →
   плитка скрыта, пока владелец не подтвердит честную цифру (никаких выдуманных чисел)»,
   а код двумя десятками строк ниже при пустом env подставляет `5000`. Одно из двух
   неверно: либо 5 000 подтверждено (тогда устарел комментарий), либо нет (тогда
   дефолт должен вернуться к `null`).
3. **Число в meta description** (пункт 6) зашито мимо конфига — если 5 000 однажды
   изменится, строка разойдется с плитками.

## Как это проверялось

- `grep -rniE "спешит|успей|только сегодня|последний шанс|осталось (мало|мест)|не упустите|торопитесь"` по `resources/`, `app/`, `config/`, `lang/`, `database/`;
- `grep -rniE "уже (сотни|тысячи)|сотни (учеников|студентов)|[0-9 ]{3,}\+? (учеников|студентов)|присоединяйтесь к|все берут|нас уже"` по тем же деревьям;
- извлечение всех кириллических литералов из контроллеров и джобов ботов;
- read-only `SELECT` по проду: `landing_pages` (9 активных, 308 КБ JSON) и текстовые
  колонки `marketing_settings`.

Непокрытое: копия в `MarketingSetting`, которую менеджер может завести **после** этой
даты без деплоя (голосовой контракт §3 называет этот носитель отдельно) — grep по
репозиторию ее не увидит никогда. Это аргумент за подключение линтера, а не за
повторный аудит.

_Dr. Mārcis Gasūns_
