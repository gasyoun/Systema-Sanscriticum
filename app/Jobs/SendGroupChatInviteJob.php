<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Group;
use App\Models\Payment;
use App\Services\Access\TelegramAdminNotifier;
use App\Services\Support\SupportReplyService;
use App\Services\Telegram\ZapisiChatMemberException;
use App\Services\Telegram\ZapisiChatMemberService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * После оплаты — ссылка-заявка в Telegram-чат группы (features.paid_chat_join_request).
 *
 * Группа: выбранная в личке (payments.group_id), иначе единственная группа
 * курса с чатом, в которой студент состоит. Неоднозначно / нет чата / бот без
 * прав — студенту ничего, кураторам алерт: ссылку отправит человек.
 *
 * Канал: где студент сейчас пишет. Разговор в личке школы (support / business)
 * → pending-исходящее туда же (увозит синк); иначе — кабинетный бот по
 * users.telegram_id. Ровно один раз на платёж: chat_invite_sent_at клеймится
 * атомарно ДО отправки и снимается, только если ссылку получить не удалось.
 */
final class SendGroupChatInviteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $paymentId) {}

    public function handle(ZapisiChatMemberService $zapisi, SupportReplyService $replies, TelegramAdminNotifier $admins): void
    {
        if (! config('features.paid_chat_join_request')) {
            return;
        }

        $payment = Payment::query()->with(['user', 'course', 'group'])->find($this->paymentId);
        if ($payment === null || $payment->user === null || $payment->chat_invite_sent_at !== null) {
            return;
        }

        $user = $payment->user;
        $groups = $this->targetGroups($payment);

        if ($groups->count() !== 1) {
            $admins->notifyAdmins(sprintf(
                '🔗 Оплата #%d (%s, «%s»): не понял, в какой чат звать (групп с чатом: %d). Пришлите ссылку вручную.',
                $payment->id,
                $user->name,
                $payment->course?->title ?? '—',
                $groups->count(),
            ));

            return;
        }

        /** @var Group $group */
        $group = $groups->first();

        // Клейм до отправки: параллельный ретрай вебхука второй ссылки не пошлёт.
        $claimed = Payment::query()->whereKey($payment->id)->whereNull('chat_invite_sent_at')
            ->update(['chat_invite_sent_at' => now()]);
        if ($claimed !== 1) {
            return;
        }

        try {
            $link = $zapisi->joinRequestLink($group);
        } catch (ZapisiChatMemberException $e) {
            Payment::query()->whereKey($payment->id)->update(['chat_invite_sent_at' => null]);
            $admins->notifyAdmins("🔗 Оплата #{$payment->id}: ссылку в чат «{$group->name}» создать не удалось ({$e->getMessage()}). Проверьте права @zapisi_ORSbot и пришлите ссылку вручную.");

            return;
        }

        $text = "Оплата получена, спасибо!\n\nСсылка для входа в чат группы «{$group->name}»: {$link}"
            ."\n\nНажмите «Подать заявку» — бот одобрит её автоматически.";

        $queued = $replies->activeChannel($user) === SupportReplyService::CHANNEL_TELEGRAM_SUPPORT
            ? $replies->queueAiReply($user, $text)
            : null;

        if ($queued === null) {
            if (empty($user->telegram_id)) {
                $admins->notifyAdmins("🔗 Оплата #{$payment->id} ({$user->name}): Telegram не привязан, отправьте ссылку в чат «{$group->name}» вручную: {$link}");

                return;
            }

            SendTelegramMessageJob::dispatch($user->id, $text);
        }

        Log::info('SendGroupChatInviteJob: join link sent', [
            'payment_id' => $payment->id,
            'group_id' => $group->id,
            'via' => $queued !== null ? 'support' : 'student_bot',
        ]);
    }

    /** @return Collection<int, Group> */
    private function targetGroups(Payment $payment): Collection
    {
        $withChat = fn (Group $g): bool => trim((string) $g->telegram_chat_id) !== '';

        if ($payment->group !== null) {
            return collect([$payment->group])->filter($withChat)->values();
        }

        if ($payment->course === null) {
            return collect();
        }

        $memberOf = $payment->user->activeGroups()->pluck('groups.id')->map(fn ($id) => (int) $id)->all();

        return $payment->course->groups()->get()
            ->filter(fn (Group $g) => $withChat($g) && in_array((int) $g->id, $memberOf, true))
            ->values();
    }
}
