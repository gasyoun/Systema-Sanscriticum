<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\Lesson;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Завести курсу-ЗАПИСИ собственные уроки со ссылками на записи живого потока
 * (H3823, рулинг MG 01-09-2026 «собственные уроки у 327 со ссылками на те же
 * записи»).
 *
 * Зачем. Курс 327 «Йога-сутры Патанджали (1 поток, 2025) в записи» продан 129
 * раз (block_1 — 40, block_2 — 33, block_3 — 28, block_4 — 28; `full` не купил
 * НИКТО), у него четыре блока и пять активных тарифов — и НОЛЬ уроков. Доступ
 * считается по курсу, поэтому купившие не получают ничего: все шестнадцать
 * записей лежат на уроках живого курса 396. MG 01-09-2026: «покупают записи,
 * ничего не лежит в Zoom-облаке, все на youtube и rutube».
 *
 * Что делает. Копирует уроки исходного курса в целевой, перенося ровно то, что
 * делает урок записью: заголовок, номер блока и половину, порядок, дату,
 * признак публикации и ссылки (`youtube_url`/`rutube_url`/`video_url`).
 * `block_number` переносится дословно — именно из него
 * {@see Lesson::unlockingKeys()} выводит `block_N`, поэтому купленный block_2
 * открывает ровно второй блок записи, без отдельной таблицы соответствий.
 *
 * Чего НЕ делает. Не трогает `courses`, `tariffs`, оплаты и видимость —
 * инцидент 31-08-2026 (H3812) состоял ровно в правке тарифов, и здесь этот путь
 * закрыт: команда пишет только в `lessons`. Не переносит `group_id` (у записи
 * своя группа 65) и не включает домашние задания: у курса-записи нет
 * проверяющего, а `homework_enabled` у источника и так 0 — но мы не полагаемся
 * на источник и выставляем 0 явно.
 *
 * По умолчанию это СУХОЙ ПРОГОН. Запись делает только `--apply`.
 * Идемпотентна: урок опознаётся парой (block_number, sort_order), поэтому
 * повторный прогон не создаёт дублей.
 */
class MirrorRecordingLessons extends Command
{
    protected $signature = 'catalog:mirror-recording-lessons
        {source : ID курса-источника, чьи уроки несут записи}
        {target : ID курса-записи, которому уроков не хватает}
        {--apply : Выполнить запись (без флага — сухой прогон)}';

    protected $description = 'Завести курсу-записи собственные уроки со ссылками на записи живого потока. Тарифы и видимость не трогает.';

    /** Поля, которые делают урок записью. Всё остальное намеренно не переносится. */
    private const CARRIED = [
        'title', 'block_number', 'block_half', 'sort_order', 'lesson_date',
        'duration_seconds', 'duration_minutes', 'is_published', 'is_free', 'is_preview',
        'video_url', 'rutube_url', 'youtube_url', 'topic', 'recording_kind',
        'recording_attached_at',
    ];

    public function handle(): int
    {
        $source = Course::find((int) $this->argument('source'));
        $target = Course::find((int) $this->argument('target'));

        if ($source === null || $target === null) {
            $this->error('Курс-источник или курс-цель не найден.');

            return self::FAILURE;
        }

        if ($source->id === $target->id) {
            $this->error('Источник и цель — один и тот же курс.');

            return self::FAILURE;
        }

        $lessons = Lesson::query()->where('course_id', $source->id)
            ->orderBy('block_number')->orderBy('sort_order')->get();

        if ($lessons->isEmpty()) {
            $this->error("У курса {$source->id} нет уроков — переносить нечего.");

            return self::FAILURE;
        }

        // Блоки цели должны покрывать блоки источника: иначе перенесённый урок
        // получил бы ключ block_N, которого у цели нет ни в блоках, ни в
        // тарифах, и остался бы недостижимым для купивших.
        $targetBlocks = $target->blocks()->pluck('number')->map(fn ($n) => (int) $n)->all();
        $needed = $lessons->pluck('block_number')->filter()->unique()->map(fn ($n) => (int) $n)->all();
        $missing = array_values(array_diff($needed, $targetBlocks));

        if ($missing !== []) {
            $this->error(sprintf(
                'У курса %d нет блоков %s, которые есть у уроков источника — перенос оставил бы их недоступными. Заведите блоки сначала.',
                $target->id,
                implode(', ', $missing),
            ));

            return self::FAILURE;
        }

        $existing = Lesson::query()->where('course_id', $target->id)
            ->get()
            ->keyBy(fn (Lesson $l) => $this->slot($l));

        $planned = [];
        foreach ($lessons as $lesson) {
            if ($existing->has($this->slot($lesson))) {
                continue;
            }
            $planned[] = $lesson;
        }

        $this->line(sprintf(
            'источник %d: уроков %d · цель %d: уроков %d · к переносу %d (пропущено как уже существующие: %d)',
            $source->id, $lessons->count(), $target->id, $existing->count(),
            count($planned), $lessons->count() - count($planned),
        ));

        foreach ($planned as $lesson) {
            $this->line(sprintf(
                '  блок %s · позиция %s · %s%s',
                (string) $lesson->block_number,
                (string) $lesson->sort_order,
                $lesson->youtube_url ? 'yt ' : '',
                $lesson->rutube_url ? 'rt' : '',
            ));
        }

        if ($planned === []) {
            $this->info('Переносить нечего — цель уже содержит все уроки источника.');

            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            $this->newLine();
            $this->info('Сухой прогон. Ничего не записано — повторите с --apply.');

            return self::SUCCESS;
        }

        $tariffsBefore = $target->tariffs()->where('is_active', true)->count();
        $visibleBefore = (bool) $target->is_visible;

        $created = 0;
        DB::transaction(function () use ($planned, $target, &$created) {
            foreach ($planned as $lesson) {
                $attributes = [];
                foreach (self::CARRIED as $field) {
                    $attributes[$field] = $lesson->getAttribute($field);
                }

                $attributes['course_id'] = $target->id;
                // Своя группа у записи — привязку живого потока не тащим.
                $attributes['group_id'] = null;
                // У курса-записи нет проверяющего домашних работ.
                $attributes['homework_enabled'] = false;
                $attributes['slug'] = $this->slugFor($lesson, $target);

                Lesson::query()->create($attributes);
                $created++;
            }
        });

        // Контроль того, чего команда касаться не должна.
        $target->refresh();
        $tariffsAfter = $target->tariffs()->where('is_active', true)->count();

        $this->info(sprintf('Создано уроков: %d.', $created));
        $this->line(sprintf(
            'Контроль: активных тарифов %d → %d, видимость %s → %s (команда их не трогает).',
            $tariffsBefore, $tariffsAfter,
            $visibleBefore ? 'да' : 'нет',
            $target->is_visible ? 'да' : 'нет',
        ));

        return self::SUCCESS;
    }

    /** Место урока в курсе: по нему и опознаётся «этот урок уже перенесён». */
    private function slot(Lesson $lesson): string
    {
        return sprintf(
            '%s|%s|%s',
            (string) $lesson->block_number,
            (string) $lesson->block_half,
            (string) $lesson->sort_order,
        );
    }

    /**
     * Слаг для перенесённого урока. У `lessons.slug` нет уникального индекса
     * (на 01-09-2026 233 урока делят пустой слаг), но одинаковый слаг у двух
     * уроков разных курсов делает ссылку неоднозначной, поэтому исходный слаг
     * получает суффикс курса-цели.
     */
    private function slugFor(Lesson $lesson, Course $target): string
    {
        $base = trim((string) $lesson->slug);

        if ($base === '') {
            $base = Str::slug((string) $lesson->title);
        }

        if ($base === '') {
            $base = 'urok';
        }

        return Str::limit($base, 180, '').'-k'.$target->id;
    }
}
