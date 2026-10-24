<?php

declare(strict_types=1);

namespace App\Services\Waitlist;

use App\Jobs\SendMessengerAlerts;
use App\Mail\ZhdunPaymentOpenMail;
use App\Models\CourseWaitlistItem;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * Ждун: сообщение голосовавшим за строку списка ожидания — «оплата
 * открыта, можно платить». Раньше при кворуме сигнал уходил только
 * куратору (CuratorNotifier::waitlistPaymentOpened), а сами голосовавшие
 * узнавали об оплате, только заглянув на витрину.
 *
 * Голосуют только зарегистрированные (waitlist_votes.user_id NOT NULL).
 * Каналы, независимо друг от друга:
 *  - мессенджеры — SendMessengerAlerts в привязанные TG/VK кабинета;
 *  - email — ZhdunPaymentOpenMail, только при wants_email_announcements
 *    (призыв к оплате — рекламное письмо, 38-ФЗ ст. 18; с отпиской).
 * Голосовавший, до которого не дошёл ни один канал, — ручной хвост, его
 * считаем, но не шлём.
 *
 * Два входа: авто из OpenWaitlistPayment (за флагом
 * features.waitlist_notify_voters, не чаще раза на строку — кэш-надгробие)
 * и ручная кнопка в админке (дедупа нет — куратор шлёт осознанно).
 */
class ZhdunVoterNotifier
{
    /** Надгробие авто-рассылки: оплата открывается один раз, дольше хранить незачем. */
    private const AUTO_TOMBSTONE_DAYS = 30;

    public static function courseTitle(CourseWaitlistItem $item): string
    {
        $course = $item->course;

        return $course !== null && filled($course->title)
            ? (string) $course->title
            : (filled($item->course_title) ? (string) $item->course_title : $item->slug);
    }

    /** Куда вести голосовавшего: страница курса, без привязки — сам ждун. */
    public static function courseUrl(CourseWaitlistItem $item): string
    {
        $course = $item->course;

        return $course !== null && filled($course->slug)
            ? route('shop.course.show', $course)
            : route('shop.waitlist');
    }

    /**
     * Текст по умолчанию. Telegram-канал SendMessengerAlerts шлёт HTML —
     * название экранируем, ссылку отдаём голым URL.
     */
    public static function paymentOpenText(CourseWaitlistItem $item): string
    {
        return 'Оплата открыта: '.e(self::courseTitle($item)).".\n\n"
            .'Вы голосовали за этот курс в списке ожидания — нужное число желающих набралось, '
            ."можно записаться и оплатить:\n".self::courseUrl($item);
    }

    /**
     * Разошлёт $text всем голосовавшим за строку.
     *
     * @return array{messengers: int, emails: int, no_channel: int}
     */
    public function notify(CourseWaitlistItem $item, string $text): array
    {
        $messengers = 0;
        $emails = 0;
        $noChannel = 0;
        $title = self::courseTitle($item);
        $url = self::courseUrl($item);

        $item->voters()->get()->each(function (User $user) use ($text, $title, $url, &$messengers, &$emails, &$noChannel): void {
            $reached = false;

            if (filled($user->telegram_id) || filled($user->vk_id)) {
                SendMessengerAlerts::dispatch($user, $text);
                $messengers++;
                $reached = true;
            }

            if (filled($user->email) && $user->wants_email_announcements) {
                Mail::to($user->email)->queue(new ZhdunPaymentOpenMail($title, $text, $url));
                $emails++;
                $reached = true;
            }

            if (! $reached) {
                $noChannel++;
            }
        });

        return ['messengers' => $messengers, 'emails' => $emails, 'no_channel' => $noChannel];
    }

    /**
     * Авто-рассылка при открытии оплаты: null — выключена флагом или уже
     * уходила по этой строке.
     *
     * @return array{messengers: int, emails: int, no_channel: int}|null
     */
    public function notifyPaymentOpened(CourseWaitlistItem $item): ?array
    {
        if (! config('features.waitlist_notify_voters', false)) {
            return null;
        }

        $key = 'waitlist:voters_payment_open:'.$item->getKey();
        if (! Cache::add($key, true, now()->addDays(self::AUTO_TOMBSTONE_DAYS))) {
            return null;
        }

        return $this->notify($item, self::paymentOpenText($item));
    }
}
