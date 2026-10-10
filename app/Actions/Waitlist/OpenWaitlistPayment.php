<?php

declare(strict_types=1);

namespace App\Actions\Waitlist;

use App\Models\Course;
use App\Models\CourseWaitlistItem;
use App\Models\Tariff;
use App\Services\CuratorNotifier;
use Illuminate\Support\Facades\Cache;

/**
 * Автооткрытие оплаты ждун-курса: строка списка ожидания, привязанная к
 * курсу, набрала кворум голосов (votes >= min_payers) — статус
 * collecting → payment_open и включение тарифов курса, чтобы «Открыта
 * оплата» на витрине вела на настоящие цены, а не в тупик. Куратору —
 * сигнал в Telegram (включено тарифов N).
 *
 * Вызов на каждом голосе безопасен: гард по порогу и состоянию внутри.
 * Гонка снимается условным UPDATE (… where status = collecting) — успех-
 * уведомление уходит только у захватившего переход. Блокирующие проблемы
 * (нет тарифов, курс скрыт с витрины) оплату НЕ открывают — куратору то
 * же уведомление не чаще раза в сутки (кэш-надгробие), из крона и из
 * голоса. Обратно оплату не закрываем: отзыв голоса ниже порога ничего
 * не выключает.
 */
class OpenWaitlistPayment
{
    public const RESULT_OPENED = 'opened';

    public const RESULT_ALREADY_OPEN = 'already_open';

    public const RESULT_THRESHOLD_NOT_MET = 'threshold_not_met';

    public const RESULT_NOT_BOUND = 'not_bound';

    public const RESULT_NO_TARIFFS = 'no_tariffs';

    public const RESULT_COURSE_HIDDEN = 'course_hidden';

    public const RESULT_DISABLED = 'flag_off';

    /** Кэш-надгробие блокирующего уведомления: раз в сутки (крон ежедневно). */
    private const BLOCKED_NOTIFY_TOMBSTONE_HOURS = 20;

    public function __construct(private readonly CuratorNotifier $notifier) {}

    public function handle(CourseWaitlistItem $item): string
    {
        if (! config('features.waitlist_auto_payment', true)) {
            return self::RESULT_DISABLED;
        }

        if ($item->status !== CourseWaitlistItem::STATUS_COLLECTING) {
            return self::RESULT_ALREADY_OPEN;
        }

        if (! $item->hasThreshold()) {
            return self::RESULT_THRESHOLD_NOT_MET;
        }

        $course = $item->course_id === null ? null : Course::find($item->course_id);
        if ($course === null) {
            return self::RESULT_NOT_BOUND;
        }

        if (! $course->is_visible) {
            $this->notifyBlocked($item, 'hidden');

            return self::RESULT_COURSE_HIDDEN;
        }

        if (! Tariff::query()->where('course_id', $course->id)->exists()) {
            $this->notifyBlocked($item, 'tariffs');

            return self::RESULT_NO_TARIFFS;
        }

        $claimed = CourseWaitlistItem::query()
            ->whereKey($item->getKey())
            ->where('status', CourseWaitlistItem::STATUS_COLLECTING)
            ->update(['status' => CourseWaitlistItem::STATUS_PAYMENT_OPEN]);
        if ($claimed === 0) {
            return self::RESULT_ALREADY_OPEN;
        }
        $item->status = CourseWaitlistItem::STATUS_PAYMENT_OPEN;

        $activated = Tariff::query()
            ->where('course_id', $course->id)
            ->where('is_active', false)
            ->update(['is_active' => true]);

        Cache::forget('public_waitlist:v1');

        $this->notifier->waitlistPaymentOpened($item, $activated);

        return self::RESULT_OPENED;
    }

    private function notifyBlocked(CourseWaitlistItem $item, string $reason): void
    {
        $key = 'waitlist:blocked_notify:'.$item->getKey();
        if (Cache::has($key)) {
            return;
        }
        Cache::put($key, true, now()->addHours(self::BLOCKED_NOTIFY_TOMBSTONE_HOURS));

        $this->notifier->waitlistQuorumBlocked($item, $reason);
    }
}
