_Created: 14-09-2026 · Last updated: 14-09-2026_

# H4474 stages 1–3: субхашит-аудио дошло до SRS — колода, пуш аудио, кнопка (GLM 5.3, 14-09-2026)

Права подтверждены MG 14-09-2026 («все свои») — врата этапа 3 сняты. Все три этапа плана [PLAN_SUBHASHITA_AUDIO_SRS_LAYER_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SUBHASHITA_AUDIO_SRS_LAYER_2026.md) §5 отгружены в одном проходе:

- **Этап 1 — фид колоды:** [resources/data/subhashita_srs_deck.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/data/subhashita_srs_deck.json) — 59 карточек (полный стих с плёнки `verse_deva`, RU-перевод тātparyam из антологии `ru`, ключ `is_num`, `audio_id`), генератор `scripts/build_subhashita_srs_deck.py` (stdlib docx-парсинг, без pandoc), импорт `subhashita:import-audio-deck` (флаг `features.subhashita_srs`, OFF по умолчанию) по образцу `ImportKoshaSrsDeckB1Demo`.
- **Этап 2 — хостинг аудио:** `scripts/subhashita_push_audio.py` — 59 mp3 (17 МБ) с Яндекс.Диска (или из `--local-source` зеркала) в `storage/app/public/srs/subhashita/<audio_id>.mp3`; идемпотентно (проверка размера, повторный запуск = skip).
- **Этап 3 — кнопка в карточке:** кода не потребовалось — обзорный blade уже рендерит `<audio controls>` для `fields['audio']` через `SrsMedia::url`; колода кладёт это поле, права подтверждены.
- **Прод-активация (deploy-остаток):** на проде `subhashita:push-audio` → `SUBHASHITA_SRS=true` → `subhashita:import-audio-deck`. До флипа флага ничего видимого не меняется. Остаток заведён GTD @DO-строкой.

_Гасунс_