<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Filament\Resources\TestimonialResource;
use App\Models\Testimonial;
use App\Services\Access\TelegramAdminNotifier;
use App\Support\TelegramSendGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Студент прислал отзыв из кабинета — сообщаем админам в Telegram, что он ждёт
 * модерации. Из очереди: сеть до api.telegram.org не должна держать запрос
 * студента. Каждому получателю — через TelegramSendGuard: отказ Telegram
 * отпускает клейм, обрыв сети держит (сообщение могло уйти).
 */
final class NotifyTestimonialSubmittedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $testimonialId) {}

    public function handle(TelegramAdminNotifier $notifier): void
    {
        $testimonial = Testimonial::find($this->testimonialId);
        if (! $testimonial instanceof Testimonial || ! $testimonial->isPending()) {
            return;
        }

        $token = (string) config('services.telegram.bot_token');
        if ($token === '') {
            return;
        }

        $text = self::message($testimonial);

        foreach ($notifier->adminChatIds() as $chatId) {
            if (! TelegramSendGuard::claim($chatId, $text)) {
                continue;
            }

            if ($notifier->send($token, $chatId, $text)) {
                continue;
            }

            if ($notifier->lastSendWasNetworkFailure()) {
                // Ответа не было — клейм держим, а остальным получателям
                // тот же api.telegram.org тоже не ответит.
                break;
            }

            TelegramSendGuard::release($chatId, $text);
        }
    }

    public static function message(Testimonial $testimonial): string
    {
        $signature = e($testimonial->author_name);
        if (filled($testimonial->city)) {
            $signature .= ', '.e($testimonial->city);
        }

        $lines = ['📝 <b>Новый отзыв на модерации</b>', $signature];
        if ($testimonial->rating) {
            $lines[0] .= ' '.str_repeat('★', $testimonial->rating);
        }
        $lines[] = '';
        $lines[] = e(Str::limit($testimonial->body, 300));
        $lines[] = '';
        $lines[] = TestimonialResource::getUrl('edit', ['record' => $testimonial]);

        return implode("\n", $lines);
    }
}
