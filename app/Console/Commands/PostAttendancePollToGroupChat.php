<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Schedule;
use App\Models\TelegramPoll;
use App\Services\Telegram\ZapisiPollService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Автоопрос о присутствии: за N минут до занятия (по умолчанию за сутки, 1440) @zapisi_ORSbot вешает в
 * Telegram-чат группы опрос «Будете на занятии?» (Буду / Опоздаю / Не смогу).
 * Опрос не анонимный: результаты поимённо — «Записи (бот)» → «Опросы», а голос
 * ученика с привязанным Telegram пишется ещё и в предупреждения о занятии
 * (ScheduleAttendanceNotice), которые видит преподаватель.
 *
 * Ничего не шлёт, если:
 *  - выключен флаг features.attendance_poll_autopost (env ATTENDANCE_POLL_AUTOPOST);
 *  - выключен бот (features.telegram_zapisi_bot);
 *  - у группы не задан telegram_chat_id;
 *  - задан список пилотных групп (ATTENDANCE_POLL_GROUP_IDS) и группы в нём нет;
 *  - до старта осталось меньше MIN_MINUTES_BEFORE — отвечать уже некогда;
 *  - на этот слот (группа, время начала) опрос уже создан.
 * Дедуп — telegram_polls (kind=attendance, group_id, schedule_start): перенос
 * занятия меняет время начала, и на новое время уйдёт новый опрос.
 */
class PostAttendancePollToGroupChat extends Command
{
    /** Меньше этого до старта — опрос не шлём: ответить и учесть уже не успеть. */
    public const MIN_MINUTES_BEFORE = 20;

    protected $signature = 'classes:post-attendance-poll {--minutes= : За сколько минут до старта вешать опрос (по умолчанию — ATTENDANCE_POLL_LEAD_MINUTES)}';

    protected $description = 'Вешает в Telegram-чат группы опрос о присутствии на предстоящем занятии, один раз на занятие.';

    public function handle(ZapisiPollService $polls): int
    {
        if (! config('features.attendance_poll_autopost')) {
            $this->info('Автоопрос о присутствии выключен флагом ATTENDANCE_POLL_AUTOPOST — пропуск.');

            return self::SUCCESS;
        }

        if (! config('features.telegram_zapisi_bot')) {
            $this->info('Бот @zapisi_ORSbot выключен (features.telegram_zapisi_bot) — пропуск.');

            return self::SUCCESS;
        }

        $lead = $this->option('minutes') !== null
            ? (int) $this->option('minutes')
            : (int) config('features.attendance_poll_lead_minutes', 1440);
        $lead = max(self::MIN_MINUTES_BEFORE + 5, $lead);

        /** @var list<int> $pilotGroupIds */
        $pilotGroupIds = (array) config('features.attendance_poll_group_ids', []);

        $schedules = Schedule::query()
            ->with(['group', 'course'])
            ->whereNotNull('group_id')
            ->whereBetween('start', [now()->addMinutes(self::MIN_MINUTES_BEFORE), now()->addMinutes($lead)])
            ->orderBy('id')
            ->get();

        // Две живые строки на один слот (инцидент 11-09-2026) не должны дать два
        // опроса — группируем по (группа, старт), как classes:post-group-link.
        $slots = $schedules->groupBy(fn (Schedule $s): string => ($s->group_id ?? 0).':'.($s->start?->format('Y-m-d H:i') ?? ''));

        $posted = 0;

        foreach ($slots as $slotRows) {
            $schedule = $slotRows->sortBy('id')->first();
            $group = $schedule->group;

            // Нет чата — вешать некуда. Ничего не помечаем: после привязки чата
            // опрос уйдёт, если до занятия ещё хватает времени.
            if ($group === null || empty($group->telegram_chat_id)) {
                continue;
            }

            if ($pilotGroupIds !== [] && ! in_array((int) $group->id, $pilotGroupIds, true)) {
                continue;
            }

            $alreadyAsked = TelegramPoll::query()
                ->where('kind', TelegramPoll::KIND_ATTENDANCE)
                ->where('group_id', $group->id)
                ->where('schedule_start', $schedule->start)
                ->exists();
            if ($alreadyAsked) {
                continue;
            }

            try {
                $polls->createAttendancePoll($schedule, $group);
                $posted++;
            } catch (\Throwable $e) {
                // Один сбойный слот не должен глушить опросы остальных групп.
                Log::warning('classes:post-attendance-poll: poll not created', [
                    'schedule_id' => $schedule->id,
                    'group_id' => $group->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Опросов о присутствии отправлено в чаты групп: {$posted}.");

        return self::SUCCESS;
    }
}
