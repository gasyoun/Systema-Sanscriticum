<?php

declare(strict_types=1);

namespace App\Services\Schedule;

use App\Jobs\SendTelegramChatMessageJob;
use App\Models\Course;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\SchedulePost;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * H4328: отправка полного поста расписания в чат обучения группы.
 *
 * Память свипа — schedule_posts (text_hash последнего отправленного текста):
 * TelegramSendGuard держит дедуп только 24 ч, а свип ходит ежедневно — без
 * своей памяти неизменённый текст ушёл бы повторно на вторые сутки.
 * Флаг-гейт features.schedule_full_post (default OFF) проверяется ДО вызова.
 */
final class SchedulePostSender
{
    /**
     * Собрать и отправить пост расписания группы в её чат обучения.
     *
     * @return string|null отправленный текст (HTML), null — не отправляли
     *                     (нет занятий / нет чата / текст не изменился)
     */
    public function sendForGroup(Group $group, bool $force = false): ?string
    {
        // Гейт-флаг — единственная точка включения всей дорожки (команды и
        // кнопка в админке проверяют его же раньше, это защита в глубину).
        if (! config('features.schedule_full_post', false)) {
            return null;
        }

        $chatId = (string) ($group->telegram_chat_id ?? '');
        if ($chatId === '') {
            return null;
        }

        $post = FullSchedulePost::forGroup($group, $this->multiGroupCourse($group));
        if ($post === null) {
            return null;
        }

        $html = $post->telegramHtml();
        $hash = hash('sha256', $html);

        if (! $force) {
            $last = SchedulePost::query()->where('group_id', $group->id)->first();
            if ($last !== null && $last->text_hash === $hash) {
                return null;
            }
        }

        SendTelegramChatMessageJob::dispatch($chatId, $html);

        SchedulePost::query()->updateOrCreate(
            ['group_id' => $group->id],
            ['text_hash' => $hash, 'sent_at' => now()],
        );

        return $html;
    }

    /**
     * Группы, чьё расписание менялось за последние $hours часов — путь свипа.
     * withTrashed(): удаление занятия тоже меняет текст поста, а soft-deleted
     * строки из обычной выборки пропадают.
     *
     * @return Collection<int, Group>
     */
    public function changedGroups(int $hours = 24): Collection
    {
        $groupIds = Schedule::withTrashed()
            ->where('updated_at', '>=', now()->subHours($hours))
            ->pluck('group_id')
            ->filter()
            ->unique()
            ->values();

        return Group::query()->whereIn('id', $groupIds)->whereNotNull('telegram_chat_id')->get();
    }

    private function multiGroupCourse(Group $group): bool
    {
        $course = $group->courses()->first();
        if ($course === null) {
            return false;
        }

        return $course->groups()->count() > 1;
    }

    /**
     * Отправить посты для всех групп курса (кнопка в админке / команда).
     *
     * @return array{sent: int, skipped: int, texts: list<string>}
     */
    public function sendForCourse(Course $course, bool $force = false): array
    {
        $sent = 0;
        $skipped = 0;
        $texts = [];

        foreach ($course->groups as $group) {
            $text = $this->sendForGroup($group, $force);
            if ($text !== null) {
                $sent++;
                $texts[] = $text;
            } else {
                $skipped++;
            }
        }

        if ($sent === 0) {
            Log::info('SchedulePostSender: course has nothing to send', ['course_id' => $course->id]);
        }

        return ['sent' => $sent, 'skipped' => $skipped, 'texts' => $texts];
    }
}
