_Created: 07-05-2026 · Last updated: 05-09-2026_

# lecture-ui

Инструментарий для подготовки лекций: конвертация транскриптов, редактор, генерация HTML. Предшественник `lecture-builder` — содержит скрипты ручного пайплайна, которые `lecture-builder` вызывает автоматически.

## Пайплайн обработки лекции

```
WhisperX JSON (транскрипт)
        ↓
1. makejson.py / makejson2.py     — WhisperX JSON → структурированный текст (.txt)
        ↓
2. Ручная правка .txt             — заголовки H2/H3, расстановка таймкодов,
                                    метки слайдов (ИИ-помощь на этом этапе)
        ↓
3. makejson.py (обратно)          — структурированный .txt → lecture.json
        ↓
4. build.py                       — lecture.json + слайды (JPG) → HTML
        ↓
lecture.html (готова к публикации)
```

## Скрипты

| Файл | Назначение |
|---|---|
| `makejson.py` | Конвертер: структурированный текст ↔ JSON. Основной формат обмена. |
| `makejson2.py` | Альтернативный конвертер для другого формата разметки. |
| `build.py` | Рендерит HTML из `lecture.json` + Jinja2-шаблон + слайды JPG. |
| `build2.py` | Альтернативный рендер (другой шаблон/формат). |
| `json_to_txt.py` | Обратный конвертер JSON → структурированный текст (для правки). |
| `extract.py` | Извлечение отдельных секций/элементов из JSON. |
| `make_text_from_json_dg.py` | Экспорт читаемого текста из JSON (флаг `--para` для нумерации абзацев). |
| `pdf_to_jpg.py` | Конвертация слайдов PDF → JPG (требует `pymupdf`: `pip install pymupdf`). |
| `editor_server.py` | WebSocket-сервер для живого редактирования лекции в браузере. |
| `yt_to_mp3.bat` | Скачивание аудио с YouTube для транскрипции. |

## Типы блоков в lecture.json

```
dialog       — чередование реплик разных участников (лектор / слушатель)
text         — монолог лектора [Лекция]
interjection — одиночная реплика другой роли внутри длинного монолога
figure       — вставка слайда: <figure><img N>
```

## Структура директорий

```
lecture-ui/
├── data/Готово/          # Готовые lecture.json
├── transcription/Готово/ # Транскрипты WhisperX (исходники)
├── timecodes/            # SRT/TXT с таймкодами с YouTube
├── txt/                  # Структурированные текстовые версии
├── output/               # Готовые HTML + CSS ассеты
├── templates/            # Jinja2-шаблоны HTML
├── Коррекция/            # Словари для правки терминов (индология, санскрит)
├── Инструкции/           # Инструкции для редакторов
└── Не_актуальны/         # Устаревшие скрипты (не использовать)
```

## Связь с lecture-builder

`lecture-builder/server.py` вызывает логику из этой папки через Python-импорты (`makejson2`, `build`). Если меняешь формат JSON здесь — проверь совместимость с `lecture-builder/pipeline.py`.

## Зависимости

```bash
pip install pymupdf          # pdf_to_jpg.py
pip install jinja2           # build.py (рендер шаблонов)
pip install websockets        # editor_server.py
```

_Dr. Mārcis Gasūns_
