# SRS — короткая карта

## Когда читать

Перед изменением колод, карточек, режимов повторения, FSRS, статистики, импорта Anki/Memrise, связи SRS с уроками, играми или праной.

## Основная модель

- Публичный каталог: `/koloda`; кабинет с сохранением прогресса: `/dvaram/koloda`; статистика: `/dvaram/koloda/stats`.
- Старые `/srs` и `/dvaram/srs` перенаправляются на `koloda`.
- Планировщик — FSRS; история ответов определяет интервалы, дневной лимит ограничивает новые карточки.
- Фича управляется `SRS_ENABLED`; флаг влияет на маршруты и пункт меню.
- SRS связан с начислением праны, поэтому изменение наград затрагивает не только учебный тренажёр.

Продуктовое описание — корневой `README.md` → «Интервальные повторения».

## Точки входа в код

| Область | Файлы |
|---|---|
| HTTP | `app/Http/Controllers/SrsController.php` |
| Конфигурация | `config/srs.php` |
| FSRS и состояние | `app/Services/Srs/Fsrs.php`, `FsrsCard.php`, `State.php`, `Rating.php` |
| Повторение | `app/Services/Srs/ReviewService.php`, `AnswerMatcher.php` |
| Подбор вариантов | `app/Services/Srs/DistractorSampler.php` |
| Связь с уроками | `app/Services/Srs/LessonFlashCardsSync.php` |
| Медиа | `app/Services/Srs/SrsMedia.php` |
| Onboarding из игр | `app/Services/Srs/SrsOnboardingFromGames.php` |

Не создавать второй планировщик или отдельный источник состояния карточки без явной миграционной модели.

## Проверки

```bash
php artisan test tests/Unit/Srs/
php artisan test tests/Feature/Srs/
```

По области изменения дополнительно:

- `FsrsTest` — алгоритм и состояние FSRS;
- `SrsReviewTest` — процесс повторения;
- `SrsStatsTest` — статистика;
- `SrsAccessControlTest` — доступ;
- `SrsPranaAwardTest` — начисление праны;
- `SrsFlagDefaultTest`, `SrsFlagOverrideTest` — feature flag;
- профильный импорт-тест — при изменении импорта конкретного формата.

Если меняется связь с учебным контентом или публичными URL, добавь соответствующие `tests/Feature/Student/`, `tests/Feature/StartChteniya/` или тесты маршрутов.

## Связанные материалы

- `ROADMAP_MEMRISE_SRS_SANSKRIT_HINDI_2026.md` — roadmap, не описание уже включённого поведения.
- `ROADMAP_GRAMMAR_TABLES_BUHLER_MEMRISE_SRS_2026.md` — roadmap грамматических таблиц.
- `docs/student-manual.md` — пользовательский сценарий.

Перед использованием roadmap как требования сверь фактическое состояние с кодом и feature flags.
