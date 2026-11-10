<?php

declare(strict_types=1);

namespace App\Services\Discipline;

use App\Filament\Resources\UserResource;
use App\Models\ChatSilenceRemoval;
use App\Models\User;
use App\Services\Access\TelegramAdminNotifier;
use App\Services\Telegram\ZapisiBanJournal;
use App\Services\Telegram\ZapisiChatMemberException;
use App\Services\Telegram\ZapisiChatMemberService;
use App\Support\Roles;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * «Бот предлагает — админ жмёт» для правила ChatSilenceRemovalEligibility.
 *
 * propose(): раз в день одно сообщение админам на студента (основной бот,
 * ADMIN_TELEGRAM_ID) с кнопками «Исключить» / «Не трогать».
 * handleCallback(): нажатие. Правило пересчитывается на момент нажатия —
 * заплатил или написал между предложением и кликом, значит не баним.
 * Банит Почтальон (ZapisiChatMemberService::kick), каждый исход — в журнал
 * chat_silence_removals и в журнал банов дашборда «Записи (бот)».
 */
class ChatSilenceRemovalService
{
    public const CALLBACK_REMOVE = 'csr:';

    public const CALLBACK_SNOOZE = 'css:';

    public function __construct(
        private readonly ChatSilenceRemovalEligibility $eligibility,
        private readonly TelegramAdminNotifier $notifier,
        private readonly ZapisiChatMemberService $zapisi,
        private readonly ZapisiBanJournal $banJournal,
    ) {}

    /**
     * Кому сегодня предлагаем исключение: eligible-кандидаты минус пары
     * (студент, чат), по которым предложение ещё «в силе» или отложено.
     *
     * @return Collection<int, Collection<int, ChatSilenceCandidate>> user_id → кандидаты по чатам
     */
    public function pendingProposals(?Carbon $now = null): Collection
    {
        $now ??= Carbon::now();

        return $this->eligibility->candidates(now: $now)
            ->filter(fn (ChatSilenceCandidate $c): bool => $c->isEligible())
            ->reject(fn (ChatSilenceCandidate $c): bool => $this->onCooldown((int) $c->user->id, $c->telegramChatId, $now))
            ->unique(fn (ChatSilenceCandidate $c): string => $c->user->id.':'.$c->telegramChatId)
            ->groupBy(fn (ChatSilenceCandidate $c): int => (int) $c->user->id);
    }

    /**
     * Записать предложения и разослать админам.
     *
     * @return array{users: int, chats: int, sent: int}
     */
    public function propose(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $repeatDays = max(1, (int) config('chat_silence_removal.propose_repeat_days', 7));
        $pending = $this->pendingProposals($now);

        $sent = 0;
        $chats = 0;

        foreach ($pending as $userId => $candidates) {
            $rows = DB::transaction(function () use ($userId, $candidates, $now, $repeatDays): Collection {
                ChatSilenceRemoval::query()
                    ->where('user_id', $userId)
                    ->where('status', ChatSilenceRemoval::STATUS_PROPOSED)
                    ->update(['status' => ChatSilenceRemoval::STATUS_EXPIRED]);

                return $candidates->map(fn (ChatSilenceCandidate $c): ChatSilenceRemoval => ChatSilenceRemoval::create([
                    'user_id' => $userId,
                    'group_id' => $c->group->id,
                    'telegram_chat_id' => $c->telegramChatId,
                    'telegram_user_id' => $c->telegramUserId,
                    'last_message_at' => $c->lastMessageAt,
                    'lesson_id' => $c->lesson?->id,
                    'block_number' => $c->lesson?->block_number,
                    'status' => ChatSilenceRemoval::STATUS_PROPOSED,
                    'proposed_at' => $now,
                    'next_offer_at' => $now->copy()->addDays($repeatDays),
                ]))->values();
            });

            $delivered = $this->notifier->notifyAdmins(
                $this->proposalText($candidates, $now),
                [[
                    ['text' => '🚫 Исключить ('.$candidates->count().')', 'callback_data' => self::CALLBACK_REMOVE.$userId],
                    ['text' => '⏸ Не трогать', 'callback_data' => self::CALLBACK_SNOOZE.$userId],
                ]],
            );

            if ($delivered === []) {
                // Не дошло ни одному админу — не держим кулдаун, завтра повторим.
                ChatSilenceRemoval::query()->whereKey($rows->pluck('id'))->update([
                    'status' => ChatSilenceRemoval::STATUS_EXPIRED,
                    'next_offer_at' => null,
                    'note' => 'предложение не доставлено админам',
                ]);

                continue;
            }

            $sent++;
            $chats += $candidates->count();
        }

        return ['users' => $pending->count(), 'chats' => $chats, 'sent' => $sent];
    }

    /**
     * Нажатие кнопки предложения (основной бот). false — callback не наш.
     *
     * @param  array<string, mixed>  $callback
     */
    public function handleCallback(array $callback): bool
    {
        $data = (string) ($callback['data'] ?? '');
        $isRemove = str_starts_with($data, self::CALLBACK_REMOVE);
        $isSnooze = str_starts_with($data, self::CALLBACK_SNOOZE);
        if (! $isRemove && ! $isSnooze) {
            return false;
        }

        $callbackId = (string) ($callback['id'] ?? '');
        $fromId = $callback['from']['id'] ?? null;
        $chatId = (string) ($callback['message']['chat']['id'] ?? '');
        $messageId = (int) ($callback['message']['message_id'] ?? 0);
        $original = (string) ($callback['message']['text'] ?? '');
        $userId = (int) substr($data, 4);

        $admin = $fromId !== null ? User::query()->where('telegram_id', (string) $fromId)->first() : null;
        if (! $this->mayDecide($admin)) {
            $this->notifier->answerCallback($callbackId, 'Недостаточно прав.');

            return true;
        }

        $now = Carbon::now();
        $rows = $this->claimProposed($userId, $isRemove, (int) $admin->id, $now);

        if ($rows->isEmpty()) {
            $this->notifier->answerCallback($callbackId, 'Уже решено.');
            $this->notifier->editMessage($chatId, $messageId, trim($original."\n\n— Уже решено (другим админом или устарело)."));

            return true;
        }

        if ($isSnooze) {
            $until = $now->copy()->addDays(max(1, (int) config('chat_silence_removal.snooze_days', 14)));
            $this->notifier->answerCallback($callbackId, 'Не трогаем');
            $this->notifier->editMessage($chatId, $messageId, trim($original."\n\n⏸ Не трогаем до ".$until->format('d.m.Y').' — '.$this->adminName($admin).'.'));

            return true;
        }

        $result = $this->execute($rows, $userId, (int) $admin->id, $now);
        $this->notifier->answerCallback($callbackId, $result['removed'] > 0 ? 'Исключён' : 'Не исключён');
        $this->notifier->editMessage($chatId, $messageId, trim($original."\n\n".$result['summary'].' — '.$this->adminName($admin).'.'));

        return true;
    }

    /**
     * Атомарно забрать открытые предложения студента: второй админ (или
     * двойное нажатие) получит пустой список, бан не повторится.
     *
     * @return Collection<int, ChatSilenceRemoval>
     */
    private function claimProposed(int $userId, bool $isRemove, int $adminId, Carbon $now): Collection
    {
        return DB::transaction(function () use ($userId, $isRemove, $adminId, $now): Collection {
            $rows = ChatSilenceRemoval::query()
                ->where('user_id', $userId)
                ->where('status', ChatSilenceRemoval::STATUS_PROPOSED)
                ->lockForUpdate()
                ->get();

            if ($rows->isEmpty()) {
                return $rows;
            }

            ChatSilenceRemoval::query()->whereKey($rows->pluck('id'))->update($isRemove
                ? [
                    'status' => ChatSilenceRemoval::STATUS_EXECUTING,
                    'decided_by' => $adminId,
                    'decided_at' => $now,
                ]
                : [
                    'status' => ChatSilenceRemoval::STATUS_DISMISSED,
                    'decided_by' => $adminId,
                    'decided_at' => $now,
                    'next_offer_at' => $now->copy()->addDays(max(1, (int) config('chat_silence_removal.snooze_days', 14))),
                    'note' => 'отложено админом',
                ]);

            return $rows->each->refresh();
        });
    }

    /**
     * @param  Collection<int, ChatSilenceRemoval>  $rows
     * @return array{removed: int, summary: string}
     */
    private function execute(Collection $rows, int $userId, int $adminId, Carbon $now): array
    {
        $repeatDays = max(1, (int) config('chat_silence_removal.propose_repeat_days', 7));

        // Пересчёт на момент нажатия: решение — по сегодняшним данным.
        $current = $this->eligibility->candidates(onlyUserId: $userId, now: $now)
            ->keyBy(fn (ChatSilenceCandidate $c): string => $c->telegramChatId);

        $removed = [];
        $failed = [];
        $dismissed = [];

        foreach ($rows as $row) {
            $label = $row->group?->name ?? $row->telegram_chat_id;
            $candidate = $current->get($row->telegram_chat_id);

            if ($candidate === null || ! $candidate->isEligible()) {
                $why = $candidate === null
                    ? 'больше нет в чате или снимок недоступен'
                    : implode(', ', array_map(ChatSilenceRemovalEligibility::blockerLabel(...), $candidate->blockers));
                $row->forceFill([
                    'status' => ChatSilenceRemoval::STATUS_DISMISSED,
                    'note' => mb_substr('правило не выполняется: '.$why, 0, 500),
                    'next_offer_at' => null,
                ])->save();
                $dismissed[] = $label.' ('.$why.')';

                continue;
            }

            try {
                $this->zapisi->kick($row->telegram_chat_id, $row->telegram_user_id, $adminId);
            } catch (Throwable $e) {
                $message = $e instanceof ZapisiChatMemberException ? $e->getMessage() : 'внутренняя ошибка';
                if (! $e instanceof ZapisiChatMemberException) {
                    Log::error('ChatSilenceRemoval: kick crashed', ['removal_id' => $row->id, 'error' => $e->getMessage()]);
                }
                $row->forceFill([
                    'status' => ChatSilenceRemoval::STATUS_FAILED,
                    'note' => mb_substr($message, 0, 500),
                    'next_offer_at' => $now->copy()->addDays($repeatDays),
                ])->save();
                $failed[] = $label.': '.$message;

                continue;
            }

            $row->forceFill(['status' => ChatSilenceRemoval::STATUS_REMOVED])->save();
            $this->banJournal->remember($row->telegram_chat_id, [
                'id' => (int) $row->telegram_user_id,
                'username' => $candidate->memberUsername,
                'name' => $candidate->memberName ?? $candidate->user?->name,
                'banned_at' => $now->toIso8601String(),
                'by_user_id' => $adminId,
            ]);
            $removed[] = $label;

            Log::info('ChatSilenceRemoval: removed', [
                'removal_id' => $row->id,
                'user_id' => $row->user_id,
                'chat_id' => $row->telegram_chat_id,
                'by_user_id' => $adminId,
            ]);
        }

        $parts = [];
        if ($removed !== []) {
            $parts[] = '🚫 Исключён из: '.implode(', ', $removed);
        }
        if ($failed !== []) {
            $parts[] = '⚠️ Не удалось: '.implode('; ', $failed);
        }
        if ($dismissed !== []) {
            $parts[] = '↩️ Не исключён: '.implode('; ', $dismissed);
        }

        return ['removed' => count($removed), 'summary' => implode("\n", $parts)];
    }

    /** Исключать вправе только admin / super_admin — как кнопка в «Должниках». */
    private function mayDecide(?User $admin): bool
    {
        return $admin !== null && ($admin->isSuperAdmin() || $admin->role === Roles::ADMIN);
    }

    private function onCooldown(int $userId, string $chatId, Carbon $now): bool
    {
        return ChatSilenceRemoval::query()
            ->where('user_id', $userId)
            ->where('telegram_chat_id', $chatId)
            ->where(fn ($q) => $q
                ->whereIn('status', [ChatSilenceRemoval::STATUS_REMOVED, ChatSilenceRemoval::STATUS_EXECUTING])
                ->orWhere(fn ($q) => $q
                    ->whereIn('status', [
                        ChatSilenceRemoval::STATUS_PROPOSED,
                        ChatSilenceRemoval::STATUS_DISMISSED,
                        ChatSilenceRemoval::STATUS_FAILED,
                    ])
                    ->where('next_offer_at', '>', $now)))
            ->exists();
    }

    /**
     * @param  Collection<int, ChatSilenceCandidate>  $candidates
     */
    public function proposalText(Collection $candidates, Carbon $now): string
    {
        $first = $candidates->first();
        $user = $first->user;

        $lines = [
            '🔇 <b>Молчит в учебном чате, урок закрыт</b>',
            e($first->displayName()).' · <a href="'.e(UserResource::getUrl('edit', ['record' => $user->id])).'">карточка</a>',
            '',
        ];

        foreach ($candidates as $c) {
            $silent = $c->lastMessageAt !== null
                ? 'последнее сообщение '.$c->lastMessageAt->format('d.m.Y')
                : 'в чат не писал';
            $lesson = $c->lesson !== null
                ? 'закрыт урок блока '.$c->lesson->block_number.' от '.$c->lesson->lesson_date?->format('d.m.Y')
                : 'урок —';
            $lines[] = '• '.e($c->group->name).': '.$silent.'; '.$lesson;
        }

        $lines[] = '';
        $lines[] = 'Порог: молчит ≥ '.$this->eligibility->silenceDays().' дн. Исключит Почтальон (hard ban, вход по ссылке закрыт до разбана в «Записи (бот)»). LMS-доступ не меняется.';

        return implode("\n", $lines);
    }

    private function adminName(User $admin): string
    {
        return (string) ($admin->name ?: $admin->email ?: 'admin#'.$admin->id);
    }
}
