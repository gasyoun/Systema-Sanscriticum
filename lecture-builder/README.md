# lecture-builder

HTTP-микросервис для сборки лекций. Используется из Laravel-админки
(панель «Конструктор лекций»).

Переиспользует логику из `../lecture-ui/` (makejson2, build) — обёртка
поверх существующего пайплайна, не дублирующая его.

## Установка

```bash
cd lecture-builder
python -m venv .venv
.venv\Scripts\activate           # Windows
# source .venv/bin/activate      # macOS/Linux
pip install -r requirements.txt
```

## Запуск

```bash
python server.py
```

По умолчанию слушает `127.0.0.1:5001`. Переменные окружения:

| Переменная | По умолчанию | Описание |
|---|---|---|
| `LECTURE_BUILDER_HOST` | `127.0.0.1` | Хост |
| `LECTURE_BUILDER_PORT` | `5001` | Порт |
| `LECTURE_BUILDER_DEBUG` | `0` | `1` — Flask debug |
| `LECTURE_BUILDER_TOKEN` | — | Если задан — каждый запрос должен иметь `X-Builder-Token` |

В Laravel `.env`:

```
LECTURE_BUILDER_URL=http://127.0.0.1:5001
LECTURE_BUILDER_TOKEN=<тот же токен>
```

## API

### `GET /health`

Проверка живости. Возвращает `{ok: true, service: "lecture-builder"}`.

### `POST /preprocess`

Принимает абсолютный путь к рабочей папке. Читает оттуда `raw/` файлы,
пишет туда же `slides/` и `data.json`.

Запрос:
```json
{
  "working_dir": "/abs/path/to/storage/app/lectures/123",
  "raw_pdf": "raw/slides.pdf",
  "raw_transcript": "raw/transcript.txt",
  "lesson_number": 3,
  "meta": {"course": "...", "lecturer": "...", "youtube": "...", "rutube": "..."}
}
```

Ответ:
```json
{"ok": true, "data_json": "data.json", "slides": ["03_01.jpg", "03_02.jpg"]}
```

`raw_transcript` может быть `.txt` (размеченный формат makejson2) или `.json`
(уже структурированный `lecture.json`).

### `POST /render`

Запрос:
```json
{"working_dir": "/abs/path/...", "data_json": "data.json"}
```

Ответ:
```json
{"ok": true, "output": "output/lecture.html"}
```

## Контракт filesystem

```
{working_dir}/
├── raw/                    ← загружено Laravel
│   ├── slides.pdf
│   └── transcript.txt
├── slides/                 ← создано preprocess
│   └── XX_NN.jpg
├── data.json               ← создано preprocess, патчится Laravel
├── backups/                ← бэкапы data.json (создаёт Laravel)
└── output/                 ← создано render
    └── lecture.html
```

## Отсутствие зависимости от Docker

Сервис рассчитан на запуск как обычный Python-процесс. Если в проде нужна
изоляция — оборачивайте в systemd / supervisor / docker-compose. Контракт
обмена через filesystem означает, что сервис должен видеть тот же диск,
что и Laravel (один хост или общий volume).
