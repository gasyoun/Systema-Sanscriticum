# Плейбук: ежемесячная 152-ФЗ-проверка suveren (samskrte.ru + samskrtam.ru)

_Created: 12-10-2026 · Last updated: 12-10-2026_

_Заказ MG 12-10-2026 («make playbook, so we can do the check monthly, repeat on samskrtam.ru as new handoff»). База: аудит [H6481](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H6481-GLM_Systema-Sanscriticum_152fz-audit-samskrte-full-contour_11.10.26.md) и рескан [H6507](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H6507-GLM_Systema-Sanscriticum_152fz-suveren-rescan-f4-pass_11.10.26.md)._

## Назначение

Ежемесячная машинная проверка обоих публичных доменов Systema сканером [suveren](https://github.com/marsilid/suveren) (152-ФЗ): политика обработки ПД, согласия в формах, cookies, трансграничная передача, хранение в РФ, HTTPS, реестр операторов РКН, реестр блокировок. Плейбук — самодостаточный spec-file: каждая месячная рука минтует хендофф с `--spec-file` на этот файл и проходит шаги 0–6, затем дописывает строку в «Журнал прогонов».

Регресс-ориентир: находка **F4** аудита H6481 («в HTML политики нет 152-ФЗ/целей/прав/отзыва») — чек «Содержание политики» должен оставаться PASS после фикса [PR #3189](https://github.com/gasyoun/Systema-Sanscriticum/pull/3189).

## Триггер

- Ежемесячно, в первые дни месяца: минт руки `GLM Systema-Sanscriticum 152fz-suveren-monthly-<ГГГГ-ММ>` со ссылкой на этот плейбук, затем исполнение по шагам.
- Авто-минт через launchd-лейн (рулинг рекуррентных: launchd+манифест+canary) — **не заведён**, отдельный @DO при визе MG.

## Шаг 0 — окружение (suveren между сессиями не переживает)

```bash
export PATH="$HOME/.local/bin:$PATH"
command -v suveren || uv tool install "suveren @ git+https://github.com/marsilid/suveren.git"
suveren update
```

Бинарник ставится в `~/.local/bin` (в PATH не входит — экспорт обязателен). `suveren update` рефрешит санкционные списки и реестр блокировок; полный скан после этого занимает ~4 с на домен.

## Шаг 1 — дешёвые live-пробы страниц политики (до скана)

samskrte.ru — HTML-блок «Ключевые положения политики», 4 литеральные фразы:

```bash
curl -s https://samskrte.ru/dokumenty/privacy | grep -c -e "152-ФЗ" -e "цели обработки" -e "права субъекта" -e "отзыв согласия"
```

Ожидание **4**. Гоча H6503: grep **case-sensitive**; строчные фразы должны стоять mid-line в буллетах; каждая фраза ровно на своей строке — count ≠ 4 означает регресс HTML-блока.

samskrtam.ru — страница политики наперёд не известна, сначала найти:

```bash
curl -s https://samskrtam.ru | grep -oiE 'href="[^"]*(privacy|politika|политик)[^"]*"' | sort -u
```

Нашли URL — тот же grep по нему; страницы нет — это и есть находка месяца (фиксировать, не чинить самому).

## Шаг 2 — полные сканы

```bash
mkdir -p /tmp/suveren-monthly && cd /tmp/suveren-monthly
suveren scan samskrte.ru  --json samskrte-$(date +%Y%m%dT%H%M).json  --no-open
suveren scan samskrtam.ru --json samskrtam-$(date +%Y%m%dT%H%M).json --no-open
```

- Скан доменный, одиночный URL не поддерживается; политика попадает в число внутренних страниц (лимит `--pages 6` по умолчанию). Проверить в выводе: «Проверено страниц: N ≥ 2» и что чек «Политика обработки персональных данных» ссылается на живую страницу.
- Если песочница режет сеть сканеру — повторить прогон без песочницы.

## Шаг 3 — машинный вердикт (без слова на веру)

Из JSON: `grade`/`score` и status каждого чека секций «Персональные данные (152-ФЗ)» и «Блокировки в России»:

```bash
python3 -c "import json,sys; d=json.load(open(sys.argv[1])); print(d.get('grade'), d.get('score'))" <файл.json>
```

Ключевой чек-регресс: **«Содержание политики» = ok**. Динамика к прошлому месяцу: `suveren diff old.json new.json`. Чего сканер НЕ видит (класс-слабость всех готовых сканеров): БД, логи, журнал согласий на сервере — это внутренний контур аудита H6481, не месячного скана.

## Шаг 4 — журнал и артефакты

Каждый прогон дописывает строку в «Журнал прогонов» ниже и прикладывает JSON в `docs/`: имя `AUDIT_152FZ_<ДОМЕН>_<ДД-ММ-ГГГГ>_suveren-<метка>_<UTC-тайм>.json`. HTML-рендер в репо не класть (viewable HTML блобом не хостится — скан воспроизводим за секунды).

## Шаг 5 — лендинг и хендофф-механика

- Правки Systema: ветка `h<id>-…` от `origin/main`, коммит только своих файлов сразу после правки (вотчер-поражённый клон — `git diff HEAD` на выживание), PR с секциями `## Evidence` + `## Merge Danger`, `gh pr merge --auto --squash`, затем проверить `state=MERGED` (chained merge молча не мержит).
- Uprava-сторона: клейм руки (`claim_handoff.py`), филл тела Acceptance/Evidence/Delivery — PR-ом **до** close (close архивирует тело с origin/main, урок H6503), close из **своего** worktree-скрипта с verifier-тройкой: `--verifier` ≠ `--tier`, `--verifier-verdict pass`, `--acceptance-evidence <http(s) URL>` (независимый субагент-перепроверка по паттерну H6481; DeepSeek-плечо на машине недоступно).
- Uprava dup-push гард стреляет на второй коммит с тем же H### — same-session follow-up с тегом `[dup-push-ok]` только после проверки, что совпавший коммит — свой предок.

## Шаг 6 — эскалация

- Любой WARN/FAIL/пропавший чек → репорт MG **той же пассой**. Содержимое политики/PDF без визы не править (мастер-документ MG, паттерн H5168). Находки класса F2 (трансграничка, правовые решения) — только человек.
- Новые confirmed-находки с агентским фиксом → минт отдельного хендоффа той же пассой (mint-at-first-artifact); человеческие → @DECIDE-строка MG.

## Журнал прогонов

| Дата (МСК) | Домен | Грейд | «Содержание политики» | Замечания | Рука | PR |
|---|---|---|---|---|---|---|
| 11-10-2026 23:51 | samskrte.ru | A/96 | PASS | только F5 (Let's Encrypt США, advisory) | H6507 | [#3192](https://github.com/gasyoun/Systema-Sanscriticum/pull/3192) |

_к.ф.н. М.Ю. Гасунс_
