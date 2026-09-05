_Created: 05-09-2026 · Last updated: 05-09-2026_

# H4185 — четыре немых маячка гибридного кабинета: причина, правка, как проверить после деплоя

Исполнитель: Opus 4.8 (`claude-opus-4-8`). Хендофф:
[H4185](https://github.com/gasyoun/Uprava/blob/main/handoffs/H4185-Opus_Systema-Sanscriticum_cabinet-hybrid-telemetry-emitters-restore_05.09.26.md).
Замер, поднявший тревогу: H4134 / [PR #2365](https://github.com/gasyoun/Systema-Sanscriticum/pull/2365).

## 1. Что было сломано

После DEPLOY №52 (флаг `CABINET_HYBRID` ON, 2026-08-21 07:51:25Z) на сопоставимых
двухнедельных окнах:

| Событие §4 | до флипа | после | трафик |
|---|---|---|---|
| `cabinet.continue.click` | 93 / 35 уник. | 3 / 1 | ↑ |
| `course.tab.view` | 21 / 10 | 0 / 0 | ↑ |
| `offer.impression` | 689 / 16 | 3 / 1 | ↑ |
| `offer.click` | 16 / 5 | 0 / 0 | ↑ |
| `cabinet.home.view` (контроль) | 3158 / 80 | 3732 / 86 | ↑ |

Контрольное событие выросло, уникальные логины +24 % — значит это не поведение
студентов, а потерянный эмиттер.

## 2. Причина (три разных, одна семья)

Слушатель живёт в
[`resources/views/student/partials/telemetry.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/student/partials/telemetry.blade.php)
и знает РОВНО два имени атрибутов: `data-track-event` (клик) и
`data-track-impression` (показ); всё остальное `data-track-*` уезжает в `data`.

1. **Переименованный атрибут.**
   [`student/hybrid/home.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/student/hybrid/home.blade.php)
   нёс `data-cabinet-event="cabinet.continue.click"` и `data-kind="…"` — разметка
   выглядела «протелеметренной», но слушатель этих имён не знает. Молчали
   `cabinet.continue.click` (2 места: POST-CTA и ссылка) и
   `cabinet.homework.rework.click`.
2. **Вкладок не было в контракте.** Вкладки «дома курса» в
   [`student/hybrid/course.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/student/hybrid/course.blade.php)
   рендерятся через Alpine `<template x-for>` и не несли ничего — отсюда ровный
   ноль по `course.tab.view` (в легаси-дашборде маячок был на кнопках вкладок).
3. **Оффер-рельс не переехал.** В легаси
   [`student/course.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/student/course.blade.php)
   `offer.impression`/`offer.click` висели на карточке **закрытого** урока
   (`kind=next-block`) — это и был источник 689 показов. В гибриде закрытые уроки
   рендерятся, а атрибутов на них нет.

Побочно найдено и починено: партиал телеметрии подключался ДВАЖДЫ на
`hybrid/home|library|progress` (в `layouts/student.blade.php` и ещё раз внутри
`@section('content')`) — два набора слушателей давали двойной счёт кликов и
импрешенов на этих страницах.

## 3. Что изменено

- `hybrid/home.blade.php` — `data-cabinet-event` → `data-track-event`,
  `data-kind` → `data-track-kind`, добавлен `data-track-surface="today-band"`.
- `hybrid/course.blade.php` — на вкладках `data-track-event="course.tab.view"` +
  `data-track-surface="course"` + `:data-track-tab="tab.id"`; на `<li>` закрытого
  урока — пара `offer.impression`/`offer.click`, `kind=next-block`, `block`,
  `course`, **под тем же гейтом `$suppressOffers`**, что и весь оффер-контур.
- `partials/telemetry.blade.php` — идемпотентная привязка (`window.__cabinetTelemetryBound`).
- Убраны три повторных `@include('student.partials.telemetry')`.

Границы, которые НЕ трогались: политика подавления офферов (recovery по-прежнему
даёт ноль офферов и ноль их маячков), доступ, платежи, флаг `CABINET_HYBRID`.
Имена событий — константы `App\Models\ActivityEvent`, ни одно не переименовано:
переименованное событие — та же авария с лишними шагами.

## 4. Регресс-пин

[`tests/Feature/Cabinet/HybridShellTelemetryBeaconsTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Cabinet/HybridShellTelemetryBeaconsTest.php)
— 7 тестов, 29 ассертов, green. Пинится не только наличие маячков, но и сам класс
аварии: ни один шаблон `views/student/hybrid/*` не имеет права нести
`data-cabinet-event=`, а слушатель обязан подключаться ровно один раз на страницу.
Соседние наборы (HybridPhase1–4, CabinetTelemetry, CabinetProbe,
CourseContinuationBanner, PaymentRecoveryCta) — 72 passed / 255 assertions.

## 5. Верификация после деплоя (остаток — не выполнено этим проходом)

Правка шаблонная, эффект виден только на живом трафике, а продуктовый репозиторий
мержит человек. Поэтому пункт 4 задания H4185 остаётся открытым:

1. Слить PR и дождаться авто-деплоя (`deploy.sh`, см.
   [docs/deploy.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/deploy.md)).
2. Через 7 суток после деплоя прогнать flip-anchored чтение тем же пробником, что
   в H4134 (`scripts/h4134_cabinet_kpi_probe.php`, приезжает с
   [PR #2365](https://github.com/gasyoun/Systema-Sanscriticum/pull/2365)).
3. **Порог приёмки:** `cabinet.continue.click`, `course.tab.view`,
   `offer.impression`, `offer.click` — все четыре строго > 0 при ≥ 2 уникальных
   студентах на каждое. Ноль хотя бы по одному = правка не долетела, а не «мало
   данных»: контрольный `cabinet.home.view` на том же окне даёт ~3.7 тыс. событий.
4. Пока чтение не сделано, три KPI эксперимента адопции (continue-CTR, воронка
   офферов, вовлечённость по вкладкам) остаются **INCONCLUSIVE**, а не PASS.

_Dr. Mārcis Gasūns_
