<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Support\CourseFamilyMatcher;
use Illuminate\Console\Command;

/**
 * Назвать курс ЗАПИСЬЮ другого курса — «одна карточка на программу» (H3807).
 *
 * Рулинг MG 31-08-2026: платная запись прошедшего потока не получает
 * собственной карточки в магазине, она вариант покупки внутри карточки живого
 * потока. Связь ставит эта команда, а не руками SQL: у неё есть проверки, а у
 * `UPDATE courses SET ...` их нет.
 *
 * Ничего не удаляет и не скрывает: страница записи остаётся живой и покупаемой
 * (у курса 327 «Йога-сутры Патанджали в записи» 129 оплат), меняется только то,
 * КАК программа представлена в ленте каталога и в `rel=canonical`. Развязать —
 * `--unlink`.
 */
class LinkCourseRecording extends Command
{
    protected $signature = 'catalog:link-recording
        {recording : id или slug курса-записи}
        {--into= : id или slug живого курса, чьей записью он является}
        {--unlink : Снять связь вместо установки}
        {--apply : Выполнить (без флага — только показать план)}';

    protected $description = 'Назвать курс записью живого курса: одна карточка на программу, canonical на живой курс. Ничего не удаляет.';

    public function handle(CourseFamilyMatcher $families): int
    {
        $recording = $this->resolve((string) $this->argument('recording'));
        if ($recording === null) {
            return self::FAILURE;
        }

        if ($this->option('unlink')) {
            return $this->unlink($recording);
        }

        $into = trim((string) ($this->option('into') ?? ''));
        if ($into === '') {
            $this->error('Не указан --into: живой курс, записью которого является этот.');

            return self::FAILURE;
        }

        $live = $this->resolve($into);
        if ($live === null) {
            return self::FAILURE;
        }

        foreach ($this->problems($recording, $live, $families) as $problem) {
            $this->error($problem);

            return self::FAILURE;
        }

        $this->line(sprintf(
            '  %d «%s» — запись курса %d «%s»',
            $recording->id, $recording->title, $live->id, $live->title,
        ));
        $this->line(sprintf('  карточка каталога: только /k/%s', $live->slug));
        $this->line(sprintf('  /k/%s остаётся покупаем, canonical → /k/%s', $recording->slug, $live->slug));

        if (! $this->option('apply')) {
            $this->newLine();
            $this->info('Сухой прогон: ничего не записано. Повторите с --apply.');

            return self::SUCCESS;
        }

        $recording->forceFill(['recording_of_course_id' => $live->id])->save();

        $this->newLine();
        $this->info('Готово. Тарифы, оплаты и доступы не тронуты.');

        return self::SUCCESS;
    }

    /**
     * Причины отказа. Каждая — про то, что связь исказила бы витрину, а не про
     * аккуратность.
     *
     * @return list<string>
     */
    private function problems(Course $recording, Course $live, CourseFamilyMatcher $families): array
    {
        if ((int) $recording->id === (int) $live->id) {
            return ['Курс не может быть записью самого себя.'];
        }

        if ($live->recording_of_course_id !== null) {
            return [sprintf(
                'Курс %d сам назван записью курса %d — цепочка записей запрещена: карточка у программы одна, а не две подряд.',
                $live->id, $live->recording_of_course_id,
            )];
        }

        if ($recording->recordings()->exists()) {
            return [sprintf(
                'На курс %d уже ссылаются как на живой другие записи — сначала развяжите их (--unlink).',
                $recording->id,
            )];
        }

        $recordingFamily = $families->familyFor($recording);
        $liveFamily = $families->familyFor($live);

        if ($recordingFamily !== $liveFamily) {
            return [sprintf(
                'Разные семьи: %d → «%s», %d → «%s». Записью можно назвать только курс той же программы.',
                $recording->id, $recordingFamily, $live->id, $liveFamily,
            )];
        }

        return [];
    }

    private function unlink(Course $recording): int
    {
        if ($recording->recording_of_course_id === null) {
            $this->warn(sprintf('Курс %d и так не назван записью — менять нечего.', $recording->id));

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '  %d «%s» перестаёт быть записью курса %d и снова получает собственную карточку',
            $recording->id, $recording->title, $recording->recording_of_course_id,
        ));

        if (! $this->option('apply')) {
            $this->newLine();
            $this->info('Сухой прогон: ничего не записано. Повторите с --apply.');

            return self::SUCCESS;
        }

        $recording->forceFill(['recording_of_course_id' => null])->save();
        $this->info('Готово.');

        return self::SUCCESS;
    }

    private function resolve(string $ref): ?Course
    {
        $course = ctype_digit($ref)
            ? Course::query()->find((int) $ref)
            : Course::query()->where('slug', $ref)->first();

        if ($course === null) {
            $this->error("Курс не найден: {$ref}");
        }

        return $course;
    }
}
