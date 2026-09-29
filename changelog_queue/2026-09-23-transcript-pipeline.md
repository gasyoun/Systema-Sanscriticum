# Стенограммы: очередь уроков и конвейер локального whisper (Opus 5 `claude-opus-5`, 23-09-2026)

- На проде 1 835 уроков, стенограммы есть у 189; у 1 642 есть запись, но расшифровки нет. У групп Гасунса — 144 урока, стенограмм 16, ждут распознавания 128 (гр.51 — 42, гр.53 — 39, Бюллер гр.27 — 36).
- `php artisan lessons:transcript-queue --teacher=2` отдаёт очередь JSON-ом (урок, курс, дата, ссылки). RuTube впереди YouTube: из России он открывается без VPN. Команда только читает.
- [`scripts/transcribe_lessons.py`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/transcribe_lessons.py) — прогон на станции: очередь → локальный whisper (faster-whisper, large-v3, видеокарта) → JSON в формате кабинета (слова с таймкодами, как у Deepgram) → `POST /api/lessons/{id}/transcript`. Состояние в файле, прогон продолжается с места обрыва, есть `--dry-run` и `--limit`. Наружу уходит только готовая стенограмма.
- У приёмки появился `quiet=1`: стенограмма привязывается без событий модели — массовая заливка не поднимает обработчики впустую. Попутно закреплено тестом: нарезку клипов в ВК запускает **публикация** урока, а не появление стенограммы, так что заливка на опубликованные уроки лавины клипов не вызывает.
- Тесты: [`TranscriptIngestPipelineTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Transcripts/TranscriptIngestPipelineTest.php) (5).

_Гасунс_
