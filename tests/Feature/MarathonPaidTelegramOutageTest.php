<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\MarathonEnrollment;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Подтверждение оплаты марафона уходит в Telegram синхронно, из fireOnPaid —
 * то есть внутри вебхука эквайринга, ПОСЛЕ того как платёж записан paid.
 *
 * Пока канал доставки бросал исключение, недостающий Telegram отдавал
 * эквайрингу 500 на уже проведённом платеже (прод 25-09-2026). Денежное
 * состояние при этом обязано остаться проведённым, а сам вебхук — не упасть.
 */
class MarathonPaidTelegramOutageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        MarketingSetting::create(['tg_bot_token' => 'MARATHON-TOKEN']);
        MarketingSetting::flushCached();
    }

    public function test_marathon_paid_is_completed_when_telegram_is_down(): void
    {
        Http::fake([
            'api.telegram.org/*' => fn () => throw new ConnectionException(
                'cURL error 28: Operation timed out after 10000 milliseconds for '
                .'https://api.telegram.org/botMARATHON-TOKEN/sendMessage'
            ),
            '*' => Http::response(['ok' => true], 200),
        ]);

        $landing = LandingPage::create([
            'title' => 'Консультация по онлайн-курсам ОРС',
            'slug' => config('marathon.landing_slug'),
            'is_active' => true,
        ]);
        $lead = Lead::factory()->create([
            'contact' => 'outage-payer@example.test',
            'landing_page_id' => $landing->id,
            'telegram_chat_id' => 777001,
        ]);
        $enrollment = MarathonEnrollment::factory()->create([
            'lead_id' => $lead->id,
            'track' => MarathonEnrollment::TRACK_PAID,
        ]);
        $user = User::factory()->create();

        $payment = Payment::create([
            'user_id' => $user->id,
            'lead_id' => $lead->id,
            'course_id' => null,
            'amount' => config('marathon.paid_track_price'),
            'tariff' => 'marathon_paid',
            'status' => 'pending',
        ]);

        // Ни один Throwable из доставки не имеет права выйти наружу: этот
        // переход статуса исполняется в вебхуке эквайринга.
        $payment->update(['status' => 'paid']);

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertNotNull(
            $enrollment->fresh()->paid_at,
            'платёж проведён, марафонец зачислен — Telegram лишь уведомление'
        );
    }
}
