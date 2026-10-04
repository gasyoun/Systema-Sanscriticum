# Карта скиллов: анонс нового курса — полный флоу

_Created: 04-10-2026 · Last updated: 04-10-2026_ · [метадок](SKILLS_MAP_COURSE_ANNOUNCEMENT_2026-10.meta.md)

Слой скиллов поверх [плейбука запуска §7](PLAYBOOK_COURSE_LAUNCH_FROM_ZERO_2026-10.md): что чем делать на каждом этапе анонса нового курса, что готово, что минтить. Упаковка курса, лендинг, приём оплат и боты — механика [плейбука §1–6](PLAYBOOK_COURSE_LAUNCH_FROM_ZERO_2026-10.md), здесь не дублируется. Канон-слой скиллов — [claude-config/commands](https://github.com/gasyoun/claude-config/tree/main/commands); ZCode-слой — `~/.agents/skills`.

## Флоу по этапам

| # | Этап | Скилл / инструмент | Статус |
|---|---|---|---|
| 1 | Факт-бриф: тарифы из предыстории, порог группы, даты, запись ([§7.1](PLAYBOOK_COURSE_LAUNCH_FROM_ZERO_2026-10.md)) | verified brief внутри [anons](https://github.com/gasyoun/claude-config/blob/main/commands/anons.md) — не выводить даты/цены из старых постов | 🟢 готов |
| 2 | Тексты в 5 регистрах под каналы | [anons](https://github.com/gasyoun/claude-config/blob/main/commands/anons.md) (копи-раздел + стилепаспорт Гасунса из RuWritingStyles) + `pishi` (инфостиль, регистры: TG-пост / @rusamskrtam / письмо курса; ZCode-слой, канона нет) + [sokratil](https://github.com/gasyoun/claude-config/blob/main/commands/sokratil.md) (санкционированный проход краткости) | 🟢 готов |
| 3 | Атрибуция: словарь UTM, `/ga/`-таблица, шаблон журнала размещений | **скилла нет**: ссылки задаются в [config/tracked_links.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/tracked_links.php), где захардкожена только кампания грамматики `m26` → **MINT `anons-attribution-mint`** (новая кампания: блок конфига + таблица ссылок + smoke `/ga/` + журнал) | 🟡 минтить |
| 4 | Креативы-плашки (Костя): ТЗ, приёмка 3 форматов, запрещённые слова | [ТЗ креативов](https://github.com/gasyoun/Uprava/blob/main/docs/CREATIVE_PLATES_TZ_2026-09.md) + [приёмка G26-C](https://github.com/gasyoun/Uprava/blob/main/docs/G26_C_CREATIVE_INTAKE_REPORT_13-09-2026.md); правила media intake внутри [anons](https://github.com/gasyoun/claude-config/blob/main/commands/anons.md); QC-конвейера (размеры + OCR запрещённых слов + флип [TSV-реестра](https://github.com/gasyoun/Uprava/blob/main/content/creative_plates_status.tsv)) нет → **MINT `plates-intake-qc`** | 🟡 минтить |
| 5 | Сторис | [anons](https://github.com/gasyoun/claude-config/blob/main/commands/anons.md) § server-side + anons v2 (`anons:validate/preview/publish`: плашка вжарена в пиксели, идемпотент, метрики; [операторская](ANONS_PUBLISHING_V2.md)) | 🟢 прод |
| 6 | Посты в свои каналы (Оповещения, @MarcisGasuns) | [anons](https://github.com/gasyoun/claude-config/blob/main/commands/anons.md) — только готовит; публикация по явной авторизации (by design) | 🟢 готов |
| 7 | VK: wall-посты и опросы | [vk_poll_crosspost.py](https://github.com/gasyoun/claude-config/blob/main/scripts/vk_poll_crosspost.py) (H5079: идемпотент, гейт авторизации, ledger) через [anons](https://github.com/gasyoun/claude-config/blob/main/commands/anons.md) | 🟢 готов |
| 8 | VK: Senler-рассылка (~3000 подписчиков) | **задокументированный manual-lane** — [ANONS_PUBLISHING_V2.md § Senler manual lane (H5935)](ANONS_PUBLISHING_V2.md): чек-лист UI Senler + `/ga/…-vk-…` из tracked_links + smoke клика + строка журнала; адаптер = апгрейд-путь после выпуска MG токена ([AdapterRegistry](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Anons/Adapters/AdapterRegistry.php) остаётся fail-closed, отказ ведёт в секцию) | 🟢 готов |
| 9 | Партнёры: «Индия Свами», «Индия сегодня» | [outreach-draft](https://github.com/gasyoun/claude-config/blob/main/commands/outreach-draft.md) (черновик, никогда не отправляет; отправка/оплата — решение MG) + [история размещений](https://github.com/gasyoun/Uprava/blob/main/reports/TELEGRAM_PARTNER_PLACEMENT_HISTORY_11-09-2026.md) | 🟢 готов |
| 10 | Email-кампания | Filament `/admin/campaigns` — [плейбук §4](PLAYBOOK_COURSE_LAUNCH_FROM_ZERO_2026-10.md); ops-поверхность, скилл не нужен | 🟣 ops-doc |
| 11 | Сайт samskrtam.ru (дубли анонса, баннеры) | [ors-wp-publish](https://github.com/gasyoun/claude-config/blob/main/commands/ors-wp-publish.md) (dry-run first, credential-gated, idempotent slug) | 🟢 готов |
| 12 | Журнал размещений (пост-фактум) | campaign-record-template внутри [anons](https://github.com/gasyoun/claude-config/blob/main/commands/anons.md) + [log-tables](https://github.com/gasyoun/claude-config/blob/main/commands/log-tables.md) | 🟢 готов |
| 13 | Метрики после запуска | `anons:ops metrics` + [metrika-goal-map](https://github.com/gasyoun/claude-config/blob/main/commands/metrika-goal-map.md) (цели Метрики на живых эмиттерах) | 🟢 готов |
| 14 | Дожим-каскад неоплативших | бот-куратор + [персоны куратора](https://github.com/gasyoun/Uprava/blob/main/docs/CURATOR_PERSONAS_SAMSKRTE_25-08-2026.md) (касание 1 → 10–11 дней → касание 2) | 🟣 ops-doc |
| 15 | Финальная приёмка кампании | Final acceptance check внутри [anons](https://github.com/gasyoun/claude-config/blob/main/commands/anons.md) | 🟢 готов |

## Порядок исполнения

1→2 (бриф и тексты) → **3 до любых публикаций** (без `/ga/`-таблицы атрибуция теряется — главный разрыв истории размещений) → 4 параллельно (плашки к дате) → 5–9 публикации по графику касаний («набор открыт → препятствия → напоминание», мин. 3 на канал за 2–3 недели, до 7 касаний) → 10–11 параллельно → 12 после каждого размещения → 13–14 по ходу → 15 в конце.

## Что минтить (1 скилл)

1. **`anons-attribution-mint`** (OxAlpha): из решения о кампании (слаг, каналы, креативы, назначение) → блок [config/tracked_links.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/tracked_links.php) + таблица готовых `/ga/`-ссылок по [UTM-стандарту](https://github.com/gasyoun/Uprava/blob/main/docs/GRAMMAR_GASUNS_UTM_STANDARD_12-09-2026.md) + smoke каждой ссылки (redirect на чистый адрес, метки в сессии) + заготовка журнала размещений.

Исполнено: **`anons-senler-lane`** (H5935, 04-10-2026) — выбор по пробам доступа пал на задокументированный manual-lane ([§ Senler manual lane](ANONS_PUBLISHING_V2.md)), адаптер — апгрейд-путь после выпуска MG токена Senler API.

_Гасунс_
