<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\MiniCourseLessonOpenedMail;
use App\Models\Lesson;
use App\Models\MiniCourseDripEmail;
use App\Models\User;
use App\Support\MiniCourseProgress;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Письма дрипа мини-курса «Подготовительная группа»: когда студенту
 * открывается очередной урок (запись + (N-1) * drip_days дней) — письмо
 * со ссылкой на урок.
 *
 * Идемпотентность — таблица mini_course_drip_emails (user, lesson уникален).
 * Если студент «отсидел» несколько открытий разом (не заходил неделю) —
 * уходит ОДНО письмо по самому свежему открытому уроку, все открытые
 * помечаются отправленными (без спама пачкой).
 */
class MiniCourseSendDripEmails extends Command
{
    protected $signature = 'mini-course:send-drip-emails';

    protected $description = 'Письма об открытии уроков мини-курса (дрип раз в 3 дня от даты записи)';

    public function handle(): int
    {
        $course = MiniCourseProgress::course();

        if ($course === null) {
            $this->info('Мини-курс не найден (config mini_courses.slug) — нечего отправлять.');

            return self::SUCCESS;
        }

        $lessons = $course->lessons()->orderBy('sort_order')->orderBy('id')->get(['id', 'title']);
        $enrolled = DB::table('course_user')
            ->where('course_id', $course->id)
            ->pluck('created_at', 'user_id');

        if ($enrolled->isEmpty() || $lessons->isEmpty()) {
            $this->info('Нет записанных студентов — нечего отправлять.');

            return self::SUCCESS;
        }

        $days = max(0, (int) config('mini_courses.drip_days', 3));
        $now = now();
        $sent = 0;

        foreach ($enrolled as $userId => $createdAt) {
            $anchor = Carbon::parse($createdAt);
            $user = User::find($userId);

            if ($user === null || $user->isAdminLike() || ! filled($user->email)) {
                continue;
            }

            // Индексы открытых уроков, письма о которых ещё не отправлялись.
            $pending = [];

            foreach ($lessons->values() as $i => $lesson) {
                $index = $i + 1;
                $openAt = $anchor->copy()->addDays(($index - 1) * $days);

                if ($openAt->gt($now)) {
                    break; // уроки дальше по порядку тоже не открыты
                }

                if (MiniCourseDripEmail::where('user_id', $userId)->where('lesson_id', $lesson->id)->exists()) {
                    continue;
                }

                $pending[$index] = $lesson;
            }

            if ($pending === []) {
                continue;
            }

            // Одно письмо — по самому свежему открытому уроку; все открытые
            // помечаем отправленными.
            $newestIndex = max(array_keys($pending));
            $lesson = $pending[$newestIndex];

            Mail::to($user->email)->send(new MiniCourseLessonOpenedMail(
                $user,
                $lesson,
                $course->title,
                route('student.lesson', [$course->slug, $lesson->id]),
            ));

            foreach (array_keys($pending) as $openedIndex) {
                MiniCourseDripEmail::create([
                    'user_id' => $userId,
                    'lesson_id' => $pending[$openedIndex]->id,
                    'sent_at' => now(),
                ]);
            }

            $sent++;
            $this->line("Письмо (урок «{$lesson->title}») → {$user->email}");
        }

        $this->info("Отправлено писем: {$sent}.");

        return self::SUCCESS;
    }
}
