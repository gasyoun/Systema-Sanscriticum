<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\Unsubscribable;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;

/**
 * Приглашение активного ученика в двухнедельный тест «Спроси Сократа»
 * (ИИ-помощник занятия, `features.lesson_ai` выключен: вкладку видят только
 * суперадмин и тестировщики — `users.lesson_ai_tester`).
 *
 * В письме НЕТ формы. Почтовые клиенты формы не отправляют, а антивирус и
 * превью ссылок нажали бы кнопку «согласен» за человека — поэтому обе кнопки
 * ведут на подписанную страницу согласия, и флаг «Тестировщик ИИ» ставит
 * только POST с этой страницы (LessonAiConsentController).
 *
 * Рекламное по своей природе (называет цену), поэтому идёт с трейтом
 * Unsubscribable: заголовки List-Unsubscribe + подвал отписки.
 */
class LessonAiInviteMail extends Mailable implements ShouldQueue
{
    use Unsubscribable;

    public function __construct(
        public User $user,
        public string $consentUrl,
        public string $declineUrl,
    ) {}

    public function envelope(): Envelope
    {
        $price = (int) config('lesson_ai.invite.price_rub_per_month');

        return new Envelope(
            subject: "Спроси Сократа: две недели теста, дальше {$price} ₽ в месяц",
        );
    }

    public function content(): Content
    {
        $invite = (array) config('lesson_ai.invite');

        return new Content(
            view: 'emails.lesson-ai.invite',
            with: [
                'startsOn' => $this->formatDate($invite['test_starts_on'] ?? null),
                'endsOn' => $this->formatDate($invite['test_ends_on'] ?? null),
                'price' => (int) ($invite['price_rub_per_month'] ?? 0),
                'dailyRequests' => (int) ($invite['daily_requests'] ?? 20),
                'senderName' => (string) ($invite['sender_name'] ?? 'Общество ревнителей санскрита'),
            ],
        );
    }

    private function formatDate(?string $date): string
    {
        if ($date === null || $date === '') {
            return '';
        }

        return Carbon::parse($date)->format('d.m.Y');
    }
}
