<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MarathonEnrollment;
use App\Models\Schedule;
use App\Services\Messaging\DeliveryChannelManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * H440/H487 — рассылает лидам марафона ссылку на запись прошедшей живой
 * консультации Дня 3. Мирроринг DeliverWebinarRecordings (webinar:deliver-
 * recordings): триггер — MG проставил Schedule.zoom_recording_url (готова
 * позже эфира), не таймер от start. Идемпотентность — recording_sent_at.
 * Оба трека получают запись (бесплатный — это его обещанный доступ к
 * консультации; платный — пересмотреть уже разобранный лично вопрос).
 */
final class DeliverMarathonRecording extends Command
{
    protected $signature = 'marathon:deliver-recording';

    protected $description = 'Разослать лидам марафона ссылку на запись живой консультации Дня 3';

    public function handle(DeliveryChannelManager $channels): int
    {
        $scheduleId = config('marathon.schedule_id');
        $schedule = $scheduleId ? Schedule::find($scheduleId) : null;

        if (! $schedule || ! $schedule->zoom_recording_url || ! $schedule->end || $schedule->end->isFuture()) {
            $this->info('Marathon recording delivery: no recording ready yet.');

            return self::SUCCESS;
        }

        $channel = $channels->get('telegram');
        $sent = 0;

        $enrollments = MarathonEnrollment::with('lead')
            ->whereNull('recording_sent_at')
            ->get();

        foreach ($enrollments as $enrollment) {
            $lead = $enrollment->lead;
            if (! $lead || ! $lead->telegram_chat_id) {
                continue;
            }

            $text = str_replace('{link}', (string) $schedule->zoom_recording_url, (string) config('marathon.recording_message'));
            $channel->sendMessage((string) $lead->telegram_chat_id, $text);
            $enrollment->update(['recording_sent_at' => now()]);
            $sent++;
            Log::info("marathon:deliver-recording — sent, enrollment #{$enrollment->id}");
        }

        $this->info("Marathon recording delivery: sent {$sent} message(s).");

        return self::SUCCESS;
    }
}
