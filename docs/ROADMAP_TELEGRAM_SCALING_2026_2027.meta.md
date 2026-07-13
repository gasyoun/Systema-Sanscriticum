# ROADMAP_TELEGRAM_SCALING_2026_2027.meta.md — метадок о `ROADMAP_TELEGRAM_SCALING_2026_2027`

_Created: 13-07-2026 · Last updated: 13-07-2026_

Метадок-компаньон для [ROADMAP_TELEGRAM_SCALING_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_TELEGRAM_SCALING_2026_2027.md) — фиксирует то, что вокруг документа (зачем он, кто читатель, что в нём ещё хрупко, как он живёт), не пересказывая его содержания.

## Предмет (Subject)

- **Документ:** [ROADMAP_TELEGRAM_SCALING_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_TELEGRAM_SCALING_2026_2027.md)
- **Назначение:** зонтичный roadmap по всей Telegram-поверхности Академии — «с чего начать масштабирование» — надстраивающий три существующих узких плана.
- **Аудитория:** MG (владелец решений), агент-исполнитель следующей сессии, Иван (host-side операционализация userbot).
- **Формат/контракт:** датированный header + byline; полные blob-URL на код/доки; фаза-0 + четыре workstream; все управляющие развилки закреплены рулингами MG.

## Provenance

- **Subject created:** 11-07-2026.
- **Metadoc authored:** 13-07-2026 (H887, Opus 4.8 `claude-opus-4-8`).
- **Next hardening:** none (запланированного нет; следующее обновление — по факту закрытия Phase 0 либо появления `/decision-record` D## по §4.2).

## Ranked improvement backlog

| # | Улучшение | Зачем | Статус |
|---|---|---|---|
| 1 | Синхронизировать статус после закрытия Phase 0 (P0.1–P0.4) | Роадмап держит «prerequisite»-язык; по мере закрытия предпосылок он устареет и введёт в заблуждение | parked (ждёт факта закрытия P0.x на проде) |
| 2 | Проставить ссылку на формальный `/decision-record` (D##) по §4.2, как только он создан | §4.2/§8-D1 ослабляют многолетний жёсткий принцип; рулинг требует D## до прод-включения — сейчас ссылки нет | parked (D## ещё не заведён) |
| 3 | Отразить ответ Ивана по §8a-D2 (что именно крутит его cron) | Единый раннер синка зафиксирован рулингом, но требует одного подтверждения от Ивана | parked (ожидание внешнего ответа) |
| 4 | Свести дельту §9 (правки устаревших доков) в done, когда PR смержен | §9 планирует правки sibling-доков в «этом же PR»; после merge пункт должен стать выполненным | parked (зависит от merge PR §9) |
| 5 | При старте WS2 связать с handoff по сегментным рассылкам (§8a-D3/D4) | Outbound-поток пока только спроектирован; при появлении handoff нужна взаимная ссылка | parked (WS2 не начат) |

## Известные ограничения / оговорки (Known limitations / caveats)

- **Планировочный документ, не отчёт о состоянии.** Часть статусов («за флагом», «спроектировано») — снимок на 11-07-2026; код на `main` мог уйти вперёд.
- **Риск устаревания.** Явно завязан на внешнюю операционализацию Ивана (userbot вне git) и на env-активацию фича-флагов — обе меняются вне этого файла, поэтому scorecard §2 и уровни зрелости §3 стареют быстрее прочего.
- **Зависит от sibling-доков.** Опирается на support-subsystem-map / ROADMAP_SUPPORT_AUTOMATION / cabinet-bot; их правки могут разойтись с §9.

## Назначение / неверное использование (Intended use / known misuse)

- **Для чего:** ответить на «насколько хороша Telegram-интеграция, куда расти, с чего начать»; дать порядок работ (Phase 0 → WS1/WS3 → WS2 → WS4) и зафиксировать рулинги MG.
- **Неверное чтение:** трактовать scorecard §2 как текущее прод-состояние (это снимок); считать §4.2/§8-D1 разрешением на авто-ответ уже сейчас (нужен `/decision-record` D## + условия-гейт); брать усилия/сроки §7 как обязательства, а не ориентиры.

## План поддержки и вывода из эксплуатации (Maintenance & sunset plan)

- **Кто поддерживает:** сессия-агент по Systema-Sanscriticum (Tier 0) при закрытии Phase 0 / старте каждого WS; MG — при пересмотре рулингов §4/§8.
- **Как выглядит архив:** когда все четыре WS доведены до прода и сезонный дедлайн (01-09-2026) пройден, зонтичный roadmap сворачивается — узкие планы (support-automation) остаются источником правды, а этот файл помечается `retired` со ссылкой на итоговое состояние.

## Deprecation status

`active`

## Связанные документы (Related documents)

- Сиблинг-карта реализации: [IMPLEMENTATION_MAP_TELEGRAM_SCALING_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_MAP_TELEGRAM_SCALING_2026_2027.md)
- Детализация WS1: [ROADMAP_SUPPORT_AUTOMATION_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SUPPORT_AUTOMATION_2026_2027.md)
- Ground truth support-кода: [support-subsystem-map.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-subsystem-map.md)
- Кабинет-бот: [cabinet-bot.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/cabinet-bot.md)
- Единая идентичность: [support-identity.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-identity.md)
- Хэндофф-происхождение: [H565](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H565-Opus_Systema-Sanscriticum_telegram_scaling_roadmap_11.07.26.md)

## Revision history

| Дата | Событие | Кто |
|---|---|---|
| 13-07-2026 | metadoc created (H887) | Opus 4.8 `claude-opus-4-8` |

_Dr. Mārcis Gasūns_
