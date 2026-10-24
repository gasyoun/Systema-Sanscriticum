# RUNBOOK — ZOOM→YouTube: retry-в-тот-же-день, дубль удаляем последним

_Created: 09-10-2026 · Last updated: 09-10-2026_

**Аудитория:** ops/агенты. **Когда:** исполнение ZOOM-воркфлоу упало с `403 playlistItemsNotAccessible` на узле «Add a playlist item», или запись легла не на тот канал / не встала в плейлист.
**Источники:** issue [#3050](https://github.com/gasyoun/Systema-Sanscriticum/issues/3050) (прецедент exec 4931, 06-10), канон-экспорт [docs/n8n/exports/zoom-1.4-admin-test.live.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/n8n/exports/zoom-1.4-admin-test.live.json) (H6298, Format Title v2.1), живая проба n8n vps91 09-10-2026.

## Какой воркфлоу живой

На vps91 ([context-ai.ru](https://context-ai.ru)) активен **`1EIqqNzMl5NNIxST` «ZOOM + АДМИНКА»** — экспорт выше соответствует ему по id; варианты с пометкой `[АРХИВ 08-10]` мертвы (проба 09-10: `n8n list:workflow`).

```bash
# рид-онли список живых ZOOM-воркфлоу:
ssh -o BatchMode=yes root@193.232.229.91 \
  "docker exec n8n-n8n-1 n8n list:workflow 2>/dev/null | grep -i ZOOM"
# 09-10-2026: 1EIqqNzMl5NNIxST|ZOOM + АДМИНКА  ← живой; [АРХИВ 08-10] … ← мёртвые
```

## Маршрут канала: ТОЛЬКО Settings.yt_channel

- Ветка определяется **исключительно** полем `yt_channel` в строке Settings (таблица Automation_DB): `hindi` → cred «YouTube account» (канал ивана `UC5vsyfvp4bxrSqzVsrgPw6w`), `ors` → cred «ОРС YouTube» (`UC5b8xpTyAzgS5ZZGEbO4I-Q`). Title в маршруте НЕ участвует.
- `yt_playlist_id` в той же строке — плейлист для узла «Add a playlist item»; он обязан принадлежать каналу креда ветки.
- Комментарий в коде узла «Resolve YT channel» («you can keep yt_channel=ors while testing») — остаток теста, не руководство.

## Симптом-класс: 403 playlistItemsNotAccessible

`403` на «Add a playlist item» приходит **ПОСЛЕ успешной загрузки видео** = чужой плейлист: `yt_playlist_id` принадлежит каналу, отличному от креда ветки. «Видео загрузилось» ≠ «запись доставлена» — всегда проверять ОБА шага (Upload a video + Add a playlist item). Прецедент 06-10: в строке 25 Settings `yt_channel` перевернули `hindi → ors`, плейлист остался иван-канала → видео легло на канал ОРС, в плейлист не встало.

## Рецепт retry-в-тот-же-день (вариант a — вернуть верную ветку)

1. **Правка `Settings.yt_channel`** в таблице Automation_DB (вручную в Google Sheet). `*** GATE ***` — меняет маршрут прод-записей.
2. **Retry упавшего exec** — один раз, осознанно, в тот же день. ⚠️ n8n Retry перезапускает ВСЕ узлы с начала, включая Upload a video → будет дубль видео; это ожидаемо, не ошибка.
3. **Удаление дубля — ПОСЛЕДНИМ шагом**: убедиться, что новый прогон долетел (видео на правильном канале + в плейлисте + урок создан в админке), и только потом удалить лишнюю копию с чужого канала (YouTube Studio или `youtube.videos.delete` с кредом канала-владельца).
4. **Контроль**: следующий повторяющийся meeting этой строки Settings отработал в правильную ветку (в прецеденте — meeting `86359382106`).

Вариант b (оставить ветку ors): заменить `yt_playlist_id` в строке Settings на плейлист канала ОРС, руками добавить уже загруженное видео в плейлист, exec **НЕ перезапускать** (иначе дубль загрузки).

## Гочи

- Слепой Retry = дубль загрузки: n8n не возобновляет с упавшего узла.
- `403` приходит ПОСЛЕ успешной загрузки — не читать как «упало на загрузке».
- Маршрут = только `yt_channel`: переименование title/топика маршрут не лечит.
- Правки строки Settings сверять с канон-экспортом (узел «Resolve YT channel»).

_Dr. Mārcis Gasūns_
