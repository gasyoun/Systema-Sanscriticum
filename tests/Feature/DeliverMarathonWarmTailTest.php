<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\MarathonEnrollment;
use App\Models\MarketingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeliverMarathonWarmTailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MarketingSetting::create([
            'tg_bot_username' => 'samskrte_bot',
            'tg_bot_token' => 'fake-tg',
        ]);
    }

    private function enrollment(array $overrides = []): MarathonEnrollment
    {
        $lead = Lead::factory()->create(['telegram_chat_id' => '12345', 'magnet_token' => Str::random(12)]);

        return MarathonEnrollment::factory()->create(array_merge(['lead_id' => $lead->id], $overrides));
    }

    public function test_sends_day1_warm_tail_message_on_day4(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $enrollment = $this->enrollment(['day0_started_at' => now()->subDays(4)]);

        $this->artisan('marathon:deliver-warm-tail')->assertSuccessful();

        $this->assertSame(1, $enrollment->fresh()->warm_tail_last_day_sent);
        Http::assertSent(fn ($req) => str_contains((string) $req['text'], 'Как вам марафон'));
    }

    public function test_does_not_send_during_the_3_day_marathon(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $this->enrollment(['day0_started_at' => now()->subDays(2)]);

        $this->artisan('marathon:deliver-warm-tail')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_does_not_resend_the_same_day(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $this->enrollment(['day0_started_at' => now()->subDays(4), 'warm_tail_last_day_sent' => 1]);

        $this->artisan('marathon:deliver-warm-tail')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_progresses_to_the_next_day_once_it_arrives(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $enrollment = $this->enrollment(['day0_started_at' => now()->subDays(5), 'warm_tail_last_day_sent' => 1]);

        $this->artisan('marathon:deliver-warm-tail')->assertSuccessful();

        $this->assertSame(2, $enrollment->fresh()->warm_tail_last_day_sent);
        Http::assertSent(fn ($req) => str_contains((string) $req['text'], 'а если не успею каждый день'));
    }

    public function test_stops_after_the_warm_tail_window_elapses(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $this->enrollment(['day0_started_at' => now()->subDays(20), 'warm_tail_last_day_sent' => 13]);

        $this->artisan('marathon:deliver-warm-tail')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_excludes_paid_enrollments(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $this->enrollment(['day0_started_at' => now()->subDays(4), 'paid_at' => now()->subDay()]);

        $this->artisan('marathon:deliver-warm-tail')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_skips_enrollment_without_telegram_chat_id(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $lead = Lead::factory()->create(['telegram_chat_id' => null]);
        MarathonEnrollment::factory()->create(['lead_id' => $lead->id, 'day0_started_at' => now()->subDays(4)]);

        $this->artisan('marathon:deliver-warm-tail')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_interpolates_host_and_coupon_placeholders(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        config(['marathon.host_name' => 'Тестовый Ведущий', 'marathon.coupon_amount' => 1000]);
        $this->enrollment(['day0_started_at' => now()->subDays(6), 'warm_tail_last_day_sent' => 2]);

        $this->artisan('marathon:deliver-warm-tail')->assertSuccessful();

        Http::assertSent(fn ($req) => str_contains((string) $req['text'], 'Тестовый Ведущий'));
    }

    public function test_wave2_enrollment_receives_the_membership_offer_variant(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        config(['marathon.warm_tail_wave2_from' => now()->subDays(20)->toDateString()]);
        // started on/after the cutoff -> membership-offer series
        $this->enrollment(['day0_started_at' => now()->subDays(15), 'warm_tail_last_day_sent' => 11]);

        $this->artisan('marathon:deliver-warm-tail')->assertSuccessful();

        Http::assertSent(fn ($req) => str_contains((string) $req['text'], 'Членство школы')
            && str_contains((string) $req['text'], '₽1000')
            && str_contains((string) $req['text'], '₽2000')
            && str_contains((string) $req['text'], 'https://samskrte.ru/klub'));
    }

    public function test_enrolment_moment_fixes_the_wave_even_after_the_cutoff_flips(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        config(['marathon.warm_tail_wave2_from' => now()->subDays(14)->toDateString()]);
        // started BEFORE the cutoff -> keeps the flagship coupon series
        $this->enrollment(['day0_started_at' => now()->subDays(15), 'warm_tail_last_day_sent' => 11]);

        $this->artisan('marathon:deliver-warm-tail')->assertSuccessful();

        Http::assertSent(fn ($req) => str_contains((string) $req['text'], 'Скидка после марафона всё еще доступна')
            && ! str_contains((string) $req['text'], 'Членство школы'));
    }

    public function test_without_a_cutoff_everyone_stays_on_the_flagship_series(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        config(['marathon.warm_tail_wave2_from' => null]);
        $this->enrollment(['day0_started_at' => now()->subDays(4)]);

        $this->artisan('marathon:deliver-warm-tail')->assertSuccessful();

        Http::assertSent(fn ($req) => str_contains((string) $req['text'], 'Как вам марафон')
            && ! str_contains((string) $req['text'], 'Членство школы'));
    }

    public function test_day5_falls_back_to_a_safe_message_without_a_fabricated_quote(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        config(['marathon.testimonial' => null]);
        $this->enrollment(['day0_started_at' => now()->subDays(8), 'warm_tail_last_day_sent' => 3]);

        $this->artisan('marathon:deliver-warm-tail')->assertSuccessful();

        Http::assertSent(fn ($req) => str_contains((string) $req['text'], 'Не собираем отзывы для галочки')
            && ! str_contains((string) $req['text'], '«'));
    }

    public function test_day5_uses_the_real_testimonial_when_configured(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        config(['marathon.testimonial' => 'Преподаю Шивананда-йогу 20 лет. Созрела.']);
        $this->enrollment(['day0_started_at' => now()->subDays(8), 'warm_tail_last_day_sent' => 3]);

        $this->artisan('marathon:deliver-warm-tail')->assertSuccessful();

        Http::assertSent(fn ($req) => str_contains((string) $req['text'], 'Преподаю Шивананда-йогу 20 лет. Созрела.'));
    }

    public function test_marks_day_12_sent_on_telegram_timeout_and_does_not_resend(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds');
        });
        $enrollment = $this->enrollment([
            'day0_started_at' => now()->subDays(15),
            'warm_tail_last_day_sent' => 11,
        ]);

        $this->artisan('marathon:deliver-warm-tail')->assertSuccessful();

        $this->assertSame(12, $enrollment->fresh()->warm_tail_last_day_sent);

        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $this->artisan('marathon:deliver-warm-tail')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(12, $enrollment->fresh()->warm_tail_last_day_sent);
    }

    public function test_does_not_mark_day_sent_on_telegram_api_error(): void
    {
        Http::fake(['*' => Http::response([
            'ok' => false,
            'description' => 'Forbidden: bot was blocked by the user',
        ], 403)]);
        $enrollment = $this->enrollment([
            'day0_started_at' => now()->subDays(15),
            'warm_tail_last_day_sent' => 11,
        ]);

        $this->artisan('marathon:deliver-warm-tail')->assertSuccessful();

        $this->assertSame(11, $enrollment->fresh()->warm_tail_last_day_sent);
    }

    public function test_timeout_on_one_enrollment_does_not_stop_the_rest(): void
    {
        $timedOut = $this->enrollment([
            'day0_started_at' => now()->subDays(15),
            'warm_tail_last_day_sent' => 11,
        ]);
        $ok = $this->enrollment([
            'day0_started_at' => now()->subDays(15),
            'warm_tail_last_day_sent' => 11,
        ]);

        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds');
            }

            return Http::response(['ok' => true], 200);
        });

        $this->artisan('marathon:deliver-warm-tail')->assertSuccessful();

        $this->assertSame(12, $timedOut->fresh()->warm_tail_last_day_sent);
        $this->assertSame(12, $ok->fresh()->warm_tail_last_day_sent);
    }
}
