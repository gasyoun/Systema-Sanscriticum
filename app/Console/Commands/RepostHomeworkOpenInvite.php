<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\Lesson;
use App\Models\LessonTelegramHook;
use App\Services\HomeworkNotifier;
use App\Services\HomeworkTelegramTagService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Перепостить в чат группы «Приём ДЗ открыт» (якорь для reply #ДЗ).
 *
 * Нужен, когда auto-open успел записать homework_enabled / homework_auto_opened_at
 * (или ДЗ включили руками), а Telegram-бот не достучался до API (сеть/VPS)
 * — postOpenInvite глотает ошибку, чтобы не откатывать открытие.
 *
 * Пример:
 *   php artisan homework:repost-open-invite 1830
 *   php artisan homework:repost-open-invite --title="#9, 05.08"
 *   php artisan homework:repost-open-invite 1830 --students
 *   php artisan homework:repost-open-invite 1830 --dry-run
 */
class RepostHomeworkOpenInvite extends Command
{
    protected $signature = 'homework:repost-open-invite
        {lesson? : id урока}
        {--title= : подстрока title, если id неизвестен}
        {--students : также повторно пушнуть студентам TG/VK (HomeworkNotifier::opened)}
        {--dry-run : только диагностика, без отправки}';

    protected $description = 'Переотправить пост «Приём ДЗ открыт» в telegram_chat_id групп урока';

    public function handle(HomeworkTelegramTagService $tags, HomeworkNotifier $notifier): int
    {
        $lesson = $this->resolveLesson();
        if (! $lesson instanceof Lesson) {
            return self::FAILURE;
        }

        $lesson->loadMissing('course', 'group');

        $this->info("Урок #{$lesson->id}: {$lesson->title}");
        $this->line('  course: '.($lesson->course?->title ?? '—').' (id='.($lesson->course_id ?? 'null').')');
        $this->line('  group_id: '.($lesson->group_id ?? 'null'));
        $this->line('  homework_enabled: '.($lesson->homework_enabled ? 'yes' : 'no'));
        $this->line('  homework_auto_opened_at: '.($lesson->homework_auto_opened_at?->toDateTimeString() ?? 'null'));
        $this->line('  recording_attached_at: '.($lesson->recording_attached_at?->toDateTimeString() ?? 'null'));

        $groups = $this->groupsForLesson($lesson);
        if ($groups->isEmpty()) {
            $this->error('Нет групп с telegram_chat_id для этого урока. '
                .'Проверьте groups.telegram_chat_id у группы курса или group_id урока.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('Целевые чаты:');
        foreach ($groups as $group) {
            $hooks = LessonTelegramHook::query()
                ->where('lesson_id', $lesson->id)
                ->where('group_id', $group->id)
                ->where('purpose', LessonTelegramHook::PURPOSE_OPEN_INVITE)
                ->count();
            $this->line(sprintf(
                '  • group #%d «%s» chat_id=%s existing_open_hooks=%d',
                $group->id,
                $group->name,
                $group->telegram_chat_id,
                $hooks,
            ));
        }

        if ($this->option('dry-run')) {
            $this->warn('dry-run: ничего не отправлено.');

            return self::SUCCESS;
        }

        if (! $lesson->homework_enabled) {
            $this->warn('homework_enabled=false — пост всё равно уйдёт (якорь для #ДЗ), '
                .'но студенты могут не видеть форму сдачи. Включите ДЗ в админке при необходимости.');
        }

        $this->newLine();
        $this->info('Шлю postOpenInvite…');
        $tags->postOpenInvite($lesson);

        $hooksAfter = LessonTelegramHook::query()
            ->where('lesson_id', $lesson->id)
            ->where('purpose', LessonTelegramHook::PURPOSE_OPEN_INVITE)
            ->count();
        $this->line("hooks open_invite для урока сейчас: {$hooksAfter}");

        if ($hooksAfter === 0) {
            $this->error('Хуков нет — Telegram sendMessage, скорее всего, снова упал. '
                .'Смотрите storage/logs на «HomeworkTelegramTagService: sendMessage» '
                .'и groups.telegram_chat_id / токен бота.');

            return self::FAILURE;
        }

        $this->info('Пост в чат(ы) отправлен (или уже был успешный hook).');

        if ($this->option('students')) {
            $this->info('Пуш студентам (TG/VK)…');
            $notifier->opened($lesson);
            $this->info('opened() вызван (ошибки каналов — в логе Homework auto-open push failed).');
        }

        return self::SUCCESS;
    }

    private function resolveLesson(): ?Lesson
    {
        $id = $this->argument('lesson');
        if ($id !== null && $id !== '') {
            $lesson = Lesson::query()->find((int) $id);
            if (! $lesson) {
                $this->error("Урок #{$id} не найден.");

                return null;
            }

            return $lesson;
        }

        $title = (string) $this->option('title');
        if ($title === '') {
            $this->error('Укажите {lesson} id или --title=…');

            return null;
        }

        $matches = Lesson::query()
            ->where('title', 'like', '%'.$title.'%')
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'title', 'course_id', 'homework_enabled']);

        if ($matches->isEmpty()) {
            $this->error("По --title={$title} ничего не найдено.");

            return null;
        }

        if ($matches->count() > 1) {
            $this->warn('Несколько уроков — уточните id:');
            foreach ($matches as $row) {
                $this->line("  #{$row->id}  {$row->title}");
            }

            return null;
        }

        return $matches->first();
    }

    /**
     * Зеркало HomeworkTelegramTagService::groupsForLesson + фильтр telegram_chat_id
     * (там при group_id чат без id молча скипается в цикле).
     *
     * @return Collection<int, Group>
     */
    private function groupsForLesson(Lesson $lesson)
    {
        if ($lesson->group_id) {
            $group = $lesson->group ?? Group::query()->find($lesson->group_id);
            if (! $group || blank($group->telegram_chat_id)) {
                return collect();
            }

            return collect([$group]);
        }

        if (! $lesson->course_id) {
            return collect();
        }

        return Group::query()
            ->whereHas('courses', fn ($q) => $q->whereKey($lesson->course_id))
            ->whereNotNull('telegram_chat_id')
            ->where('telegram_chat_id', '!=', '')
            ->get();
    }
}
