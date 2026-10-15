<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Consent;
use App\Models\FollowUpTask;
use App\Services\Consent\ConsentRecorder;
use App\Services\CuratorNotifier;
use App\Services\Support\SupportConversationManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * 152-ФЗ: управление согласиями из кабинета (раздел «Уведомления и рассылки»).
 *
 *  - переключатели рекламной рассылки на почту и в мессенджеры — дать/отозвать
 *    согласие в один клик (ст. 9 ч. 2), каждое изменение пишется в журнал consents;
 *  - «Запросить удаление данных» (ст. 21) — задача куратору со сроком 30 дней +
 *    уведомление в TG. Само удаление — вручную: часть данных (оплаты) обязаны
 *    храниться по налоговому учёту.
 */
class PrivacySettingsController extends Controller
{
    public function updateNotifications(Request $request, ConsentRecorder $consents): RedirectResponse
    {
        $user = $request->user();
        $email = $request->boolean('email_announcements');
        $messenger = $request->boolean('messenger_announcements');

        $before = (bool) $user->wants_email_announcements || (bool) $user->wants_messenger_announcements;

        $user->forceFill([
            'wants_email_announcements' => $email,
            'wants_messenger_announcements' => $messenger,
            'newsletter_subscribed_at' => $email ? $user->newsletter_subscribed_at : null,
        ])->save();

        $after = $email || $messenger;
        if ($after && ! $before) {
            $consents->given(Consent::TYPE_PROMO, 'cabinet:settings', $request, $user);
        } elseif (! $after && $before) {
            $consents->withdrawn(Consent::TYPE_PROMO, 'cabinet:settings', $request, $user);
        } elseif ($after) {
            // Сменили канал — фиксируем актуальное согласие.
            $consents->given(Consent::TYPE_PROMO, 'cabinet:settings', $request, $user);
        }

        return back()->with('privacy_status', $after
            ? 'Настройки рассылки сохранены.'
            : 'Вы отписаны от рассылок. Письма об оплате и доступе к урокам продолжат приходить.');
    }

    public function requestDeletion(Request $request, ConsentRecorder $consents, SupportConversationManager $conversations, CuratorNotifier $notifier): RedirectResponse
    {
        $request->validate(['confirm' => ['accepted']], [
            'confirm.accepted' => 'Подтвердите, что хотите удалить данные.',
        ]);

        $user = $request->user();

        $key = 'pd-deletion:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 1)) {
            return back()->with('privacy_status', 'Запрос уже отправлен — куратор свяжется с вами.');
        }
        RateLimiter::hit($key, 86400);

        $deadline = now()->addDays(30);

        $thread = $conversations->openFor($user);
        FollowUpTask::create([
            'deal_id' => null,
            'support_conversation_id' => $thread->id,
            'assigned_to' => null,
            'type' => FollowUpTask::TYPE_OTHER,
            'due_at' => $deadline->copy()->subDays(5),
            'note' => 'Запрос на удаление персональных данных (152-ФЗ ст. 21). Ответить до '.$deadline->format('d.m.Y').'.',
            'done_at' => null,
        ]);

        $consents->withdrawn(Consent::TYPE_PD, 'cabinet:deletion-request', $request, $user);

        try {
            $notifier->personalDataDeletionRequested($user, $deadline);
        } catch (\Throwable $e) {
            Log::warning('PD deletion request: TG notify failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        return back()->with('privacy_status', 'Запрос на удаление данных принят. Куратор свяжется с вами в течение 30 дней.');
    }
}
