# Плашки занятий — runbook

_Created: 22-09-2026 · Last updated: 22-09-2026_

Плашка занятия = фон из PSD курса + дата и номер занятия из расписания LMS.
Потребитель — n8n-воркфлоу **ZOOM 1.4**: после записи он ищет в папке группы на
Google Диске файл `ГГГГ-ММ-ДД.jpg` и ставит его обложкой записи. Этот контур
делает файлы заранее и сам.

```
PSD курса ──(1 раз, scripts/banner_template_from_psd.py)──▶ background.png + template.json
                                                                   │  /admin/lesson-banners
                                                                   ▼
schedules (дата, «(#N, …)» в титуле) ──▶ lesson-banners:render (04:40) ──▶ JPEG на public-диске
                                                                   │  GET /api/lesson-banners/due
                                                                   ▼
                          n8n «Плашки занятий» (05:10) ──▶ Automation_DB/Settings[meeting_id].drive_folder_id
                                                                   │  upload / update «ГГГГ-ММ-ДД.jpg»
                                                                   ▼
                          POST /api/lesson-banners/{id}/delivered ──▶ статус в /admin/lesson-banners
```

## Правила, которые легко сломать

- **Номер занятия — только из тега титула** `(#13, 08.09.26)` через
  `LessonSeriesInfo::number()`. Нет тега → статус «нет номера в титуле», плашка не
  рисуется. Обзорное занятие (`is_overview`) получает `overview_text` шаблона.
- **Имя файла — UTC-дата старта**, текст на плашке — московская дата. Так ZOOM 1.4
  ищет файл (`start_time.split('T')[0]` из вебхука Zoom). Занятие в 01:00 МСК 24.09
  лежит на Диске как `2026-09-23.jpg`. Это не баг.
- **Шрифты — вне git.** Шрифты плашек коммерческие, репозиторий публичный. Файлы
  грузятся в «Новый шаблон» и лежат в `storage/app/lesson-banner-fonts`
  (`LESSON_BANNERS_FONTS_DIR`). Нет шрифта → поле рисуется запасным DejaVu Sans,
  страница показывает «не загружены: …».
- Перерисовка — по `render_hash` (версия шаблона + номер + дата + имя файла).
  Перенос занятия или новая версия шаблона сбрасывает доставку, и n8n заменяет файл.

## Завести шаблон курса

```bash
pip install "psd-tools[composite]" fonttools
python scripts/banner_template_from_psd.py plashka.psd --list          # имена слоёв
python scripts/banner_template_from_psd.py plashka.psd \
    --date-layer "25.02.2026" --number-layer "(1) " --date-format "DD.MM.YYYY" \
    --out out/kurs
```

Формат номера скрипт выводит сам («(1)» → «({N})»). Формат даты —
`isoFormat` Carbon в ru-локали: `DD.MM.YYYY`, `D MMMM`, `D MMMM, dddd`. Рамка поля
расширяется под длинный текст (`--grow`, по умолчанию 1.6), но не заходит на
соседние текстовые слои; что не влезло — ужимается (`fit`).

Дальше `/admin/lesson-banners` → «Новый шаблон»: курс (и группа, если плашка у
группы своя), `background.png`, `template.json`, файлы шрифтов, PSD для учёта →
**«Превью»** (пробная дата завтра, №12). Что в превью, то и уйдёт на Диск.

## Включение (прод, отдельным шагом)

1. `.env`: `LESSON_BANNERS=true`, `N8N_LESSON_BANNERS_SECRET=<случайная строка>` →
   `php artisan config:cache`.
2. `/admin/lesson-banners` → «Отрисовать сейчас» — проверить статусы.
3. n8n: импортировать [`docs/n8n/exports/lesson-banners.json`](n8n/exports/lesson-banners.json),
   создать креденшл Header Auth «Systema lesson banners (X-Webhook-Secret)»
   (Name `X-Webhook-Secret`, Value = секрет из п.1) и привязать к трём LMS-узлам;
   Google-креденшлы те же, что у ZOOM 1.4. Прогнать вручную, проверить файл в папке
   группы, активировать.

Статусы доставки: «в папке группы» · «нет папки группы» (для `meeting_id` занятия
нет строки в Automation_DB/Settings или у занятия нет Zoom-встречи) · «ошибка»
(текст — в подсказке). Недоставленные n8n повторяет каждое утро.

## Код

`config/lesson_banners.php` · `app/Services/Banners/*` · `app/Console/Commands/RenderLessonBanners.php` ·
`app/Http/Controllers/Api/LessonBannerController.php` · `app/Filament/Pages/LessonBanners.php` ·
тесты `tests/Feature/Banners/*`.
