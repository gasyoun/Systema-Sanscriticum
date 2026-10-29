<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\TelegramScheduleReschedule;
use App\Services\Schedule\ScheduleMover;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** One-hour, actor-gated inverse of AnnounceRescheduleService. */
final class AnnounceRescheduleCallbackService
{
    private const PREFIX = 'arsu:';

    public function handle(array $callback): bool
    {
        $data = (string) ($callback['data'] ?? '');
        if (! str_starts_with($data, self::PREFIX)) {
            return false;
        }

        $callbackId = (string) ($callback['id'] ?? '');
        $fromId = $callback['from']['id'] ?? null;
        $chatId = $callback['message']['chat']['id'] ?? null;
        $receiptMessageId = $callback['message']['message_id'] ?? null;
        if (! is_numeric($fromId) || ! is_numeric($chatId) || ! is_numeric($receiptMessageId)) {
            $this->answer($callbackId);

            return true;
        }

        $chatId = (string) $chatId;
        $receiptMessageId = (int) $receiptMessageId;
        $action = TelegramScheduleReschedule::with(['schedule', 'group'])->find((int) substr($data, strlen(self::PREFIX)));
        if ($action === null || $action->schedule === null || $action->group === null) {
            $this->edit($chatId, $receiptMessageId, 'Действие переноса не найдено.');
            $this->answer($callbackId);

            return true;
        }

        $actor = app(TelegramCommandAcl::class)->resolve((int) $fromId);
        if ($actor === null || ! $this->mayUndo($actor, $action)) {
            $this->answer($callbackId, 'Недостаточно прав.');

            return true;
        }

        if ($action->status !== TelegramScheduleReschedule::STATUS_APPLIED) {
            $this->edit($chatId, $receiptMessageId, 'Перенос уже отменён.');
            $this->answer($callbackId);

            return true;
        }

        if ($action->undo_expires_at->isPast()) {
            $this->edit($chatId, $receiptMessageId, 'Час на отмену переноса истёк. Измените расписание в панели.');
            $this->answer($callbackId, 'Окно истекло');

            return true;
        }

        $schedule = $action->schedule;
        if (! $this->sameMoment($schedule->start, $action->new_start)
            || ! $this->sameMoment($schedule->end, $action->new_end)) {
            $this->edit($chatId, $receiptMessageId, 'Расписание уже изменено снова — автоматическая отмена переноса недоступна.');
            $this->answer($callbackId, 'Есть более новое изменение');

            return true;
        }

        app(ScheduleMover::class)->reschedule($schedule, $action->old_start, $action->old_end);
        $action->status = TelegramScheduleReschedule::STATUS_UNDONE;
        $action->save();

        $this->edit($chatId, $receiptMessageId, sprintf(
            'Перенос отменён. Занятие снова %s (МСК).',
            $action->old_start->format('d.m.Y H:i'),
        ));
        $this->answer($callbackId, 'Перенос отменён');

        return true;
    }

    /** @param array{role: string, teacher: mixed, user: object} $actor */
    private function mayUndo(array $actor, TelegramScheduleReschedule $action): bool
    {
        if (TelegramCommandAcl::managesAll($actor['role'])) {
            return true;
        }

        if ((string) $action->actor_telegram_id !== (string) ($actor['user']->telegram_id ?? '')) {
            $socialMatch = $actor['user']->socialAccounts()
                ->where('provider', 'telegram')
                ->where('provider_id', (string) $action->actor_telegram_id)
                ->exists();
            if (! $socialMatch) {
                return false;
            }
        }

        $teacher = $actor['teacher'];

        return $teacher !== null && Group::ledBy($teacher->id)->whereKey($action->group_id)->exists();
    }

    private function sameMoment(?Carbon $left, ?Carbon $right): bool
    {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }

        return $left->eq($right);
    }

    private function edit(string $chatId, int $messageId, string $text): void
    {
        $this->request('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
        ]);
    }

    private function answer(string $callbackId, ?string $text = null): void
    {
        if ($callbackId === '') {
            return;
        }

        $payload = ['callback_query_id' => $callbackId];
        if ($text !== null) {
            $payload['text'] = $text;
            $payload['show_alert'] = false;
        }
        $this->request('answerCallbackQuery', $payload);
    }

    /** @param array<string, mixed> $payload */
    private function request(string $method, array $payload): void
    {
        $token = (string) (MarketingSetting::cached()?->zapisi_bot_token ?? '');
        if ($token === '') {
            $token = (string) (config('services.telegram.student_bot_token') ?: config('services.telegram.bot_token') ?: '');
        }
        if ($token === '') {
            return;
        }

        try {
            Http::connectTimeout(5)->timeout(15)->post("https://api.telegram.org/bot{$token}/{$method}", $payload);
        } catch (\Throwable $exception) {
            Log::warning('AnnounceRescheduleCallback: Telegram request failed', [
                'method' => $method,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
