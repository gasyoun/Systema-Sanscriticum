<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Filament\Pages\Helpdesk;
use App\Models\SupportConversation;
use App\Models\User;
use App\Support\Roles;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Колокольчик о новом техническом вопросе из чатов юзербота.
 *
 * До этого очередь «Техника» была немой: вопрос попадал во вкладку Helpdesk и
 * ждал, пока кто-нибудь туда заглянет. Уведомление шлётся ОДИН раз — на переходе
 * треда в очередь «Техника» (см. TechnicalIssueRouter), иначе каждое следующее
 * сообщение того же студента звенело бы заново.
 *
 * Получатель: закреплённый техспециалист (support_tech.assignee_user_id), а если
 * он не задан — админы. Тот же паттерн дежурного алерта, что у
 * telegram-support:healthcheck.
 */
class TechnicalIssueNotifier
{
    public function newTechnicalIssue(SupportConversation $thread, string $text): void
    {
        try {
            $recipients = $this->recipients();

            if ($recipients->isEmpty()) {
                return;
            }

            $who = $thread->displayName();
            $preview = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
            if (mb_strlen($preview) > 160) {
                $preview = mb_substr($preview, 0, 160).'…';
            }

            foreach ($recipients as $recipient) {
                Notification::make()
                    ->title('Технический вопрос: '.$who)
                    ->warning()
                    ->body($preview !== '' ? $preview : 'Сообщение без текста.')
                    ->actions([
                        Action::make('open')
                            ->label('Открыть Helpdesk')
                            ->url(Helpdesk::getUrl().'?tab=tech', shouldOpenInNewTab: true),
                    ])
                    ->sendToDatabase($recipient);
            }
        } catch (Throwable $e) {
            // Уведомление — не причина терять сам вопрос: тред уже создан, синк
            // не должен падать из-за колокольчика.
            Log::warning('TechnicalIssueNotifier: не удалось отправить уведомление', [
                'thread_id' => $thread->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return Collection<int, User>
     */
    private function recipients(): Collection
    {
        $assigneeId = config('support_tech.assignee_user_id')
            ?? config('services.telegram_support.tech_assignee_user_id');

        if ($assigneeId) {
            $assignee = User::query()->whereKey((int) $assigneeId)->get();

            if ($assignee->isNotEmpty()) {
                return $assignee;
            }
        }

        return User::query()
            ->whereIn('role', [Roles::SUPER_ADMIN, Roles::ADMIN])
            ->get();
    }
}
