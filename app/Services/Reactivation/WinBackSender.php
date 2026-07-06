<?php

declare(strict_types=1);

namespace App\Services\Reactivation;

use App\Models\Course;
use App\Models\DebtWinBackAttempt;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\DebtorReminderDispatcher;

/**
 * Отправка шаблона реактивации «уснувшему» студенту с журналированием попытки
 * (H221). Единая точка для страницы «Реактивация» и бакета оттока в кокпите:
 *   - дедуп в окне 24 ч (одного «призрака» не бьём повторно);
 *   - рендер плейсхолдеров под получателя ({@see MessageTemplate::render});
 *   - доставка по доступным каналам через {@see DebtorReminderDispatcher};
 *   - запись {@see DebtWinBackAttempt}.
 */
class WinBackSender
{
    public function __construct(private DebtorReminderDispatcher $dispatcher) {}

    /**
     * @return array{status: 'sent'|'skipped'|'no_channels'|'no_user', channels: list<string>}
     */
    public function send(
        int $userId,
        ?int $courseId,
        ?int $blockNumber,
        MessageTemplate $template,
        ?User $actor = null,
    ): array {
        $user = User::find($userId);
        if (! $user) {
            return ['status' => 'no_user', 'channels' => []];
        }

        // Дедуп: недавно уже писали этому студенту — не дёргаем повторно.
        if (DebtWinBackAttempt::sentRecentlyTo($userId)) {
            return ['status' => 'skipped', 'channels' => []];
        }

        $channels = $this->availableChannels($user);
        if ($channels === []) {
            return ['status' => 'no_channels', 'channels' => []];
        }

        $course = $courseId ? Course::find($courseId) : null;
        $rendered = $template->render($user, $course, $blockNumber);

        $ok = $this->dispatcher->send(
            $user,
            (int) ($courseId ?? 0),
            $blockNumber,
            // Текст уже отрендерен — второй проход плейсхолдеров в диспетчере
            // будет no-op (плейсхолдеров не осталось).
            $rendered,
            (string) $template->title,
            in_array('tg', $channels, true),
            in_array('vk', $channels, true),
            in_array('email', $channels, true),
        );

        if (! $ok) {
            return ['status' => 'no_channels', 'channels' => []];
        }

        DebtWinBackAttempt::create([
            'user_id' => $userId,
            'template_id' => $template->id,
            'sent_by' => $actor?->id,
            'channel' => implode(',', $channels),
            'sent_at' => now(),
        ]);

        return ['status' => 'sent', 'channels' => $channels];
    }

    /** @return list<string> подмножество ['tg','vk','email'] */
    private function availableChannels(User $user): array
    {
        $channels = [];
        if (! empty($user->telegram_id)) {
            $channels[] = 'tg';
        }
        if (! empty($user->vk_id)) {
            $channels[] = 'vk';
        }
        if (filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            $channels[] = 'email';
        }

        return $channels;
    }
}
