<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Services\Schedule\FullSchedulePost;
use App\Services\Schedule\SchedulePostSender;
use Illuminate\Console\Command;

/**
 * H4328: полный пост расписания курса в чаты обучения групп.
 *
 *   courses:post-schedule {courseId} {--force} {--dry}  — курс вручную
 *   courses:post-schedule --due {--dry}                 — ежедневный свип:
 *     группы с расписанием, менявшимся за последние 24 ч (любой путь —
 *     перенос mover'ом, ручная правка даты, перегенерация, удаление),
 *     отправка ТОЛЬКО при смене текста (hash в schedule_posts).
 *
 * Гейт features.schedule_full_post (default OFF).
 */
class PostCourseSchedule extends Command
{
    protected $signature = 'courses:post-schedule
        {courseId? : ID курса (или все его группы)}
        {--due : Свип — группы с изменённым за 24 ч расписанием}
        {--force : Отправить даже если текст не изменился}
        {--dry : Показать текст, не отправлять}';

    protected $description = 'Полный пост расписания курса (обзорное + занятия 1–N) в чаты обучения Telegram';

    public function handle(SchedulePostSender $sender): int
    {
        if (! config('features.schedule_full_post', false)) {
            $this->info('Флаг SCHEDULE_FULL_POST выключен (features.schedule_full_post) — пропуск.');

            return self::SUCCESS;
        }

        if ($this->option('dry')) {
            return $this->dryRun();
        }

        if ($this->option('due')) {
            return $this->sweep($sender);
        }

        $courseId = $this->argument('courseId');
        if ($courseId === null) {
            $this->error('Укажите ID курса или --due для свипа.');

            return self::FAILURE;
        }

        $course = Course::find((int) $courseId);
        if ($course === null) {
            $this->error('Курс #'.$courseId.' не найден.');

            return self::FAILURE;
        }

        $result = $sender->sendForCourse($course, (bool) $this->option('force'));
        $this->info("Отправлено постов: {$result['sent']} (пропущено: {$result['skipped']}).");

        return self::SUCCESS;
    }

    private function sweep(SchedulePostSender $sender): int
    {
        $groups = $sender->changedGroups(24);

        $sent = 0;
        foreach ($groups as $group) {
            if ($sender->sendForGroup($group) !== null) {
                $sent++;
            }
        }

        $this->info('Свип: групп к проверке '.count($groups).", отправлено постов: {$sent}.");

        return self::SUCCESS;
    }

    private function dryRun(): int
    {
        $courseId = $this->argument('courseId');
        if ($courseId === null) {
            $this->error('Для --dry укажите ID курса.');

            return self::FAILURE;
        }

        $course = Course::find((int) $courseId);
        if ($course === null) {
            $this->error('Курс #'.$courseId.' не найден.');

            return self::FAILURE;
        }

        $posts = FullSchedulePost::forCourse($course);
        if ($posts === []) {
            $this->warn('У курса нет занятий — пост не строится.');

            return self::SUCCESS;
        }

        foreach ($posts as $post) {
            $this->line($post->text());
            $this->newLine();
            $this->line('──── Telegram HTML ────');
            $this->line($post->telegramHtml());
            $this->newLine();
        }

        return self::SUCCESS;
    }
}
