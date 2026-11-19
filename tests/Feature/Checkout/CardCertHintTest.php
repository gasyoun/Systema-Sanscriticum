<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use App\Models\Course;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\Tariff;
use App\Models\User;
use App\Services\Telegram\SlotNoticeService;
use App\Support\CardCertHint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 19-11-2026: НСПК перешла на сертификаты Минцифры — оплата картой в браузере
 * без них падает с ошибкой. Подсказка «СБП или Яндекс Браузер» в чекауте и
 * в TG-напоминании об оплате, за флагом features.checkout_card_cert_hint.
 */
class CardCertHintTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function tariff(): Tariff
    {
        $course = Course::factory()->create();
        $course->groups()->attach(Group::create(['name' => 'G-card-hint'])->id);

        return Tariff::factory()->for($course)->create(['price' => 5000, 'is_active' => true]);
    }

    public function test_checkout_shows_hint_when_flag_on(): void
    {
        config(['features.checkout_card_cert_hint' => true]);

        $this->actingAs(User::factory()->create())
            ->get(route('checkout.show', $this->tariff()))
            ->assertOk()
            ->assertSee('data-testid="card-cert-hint"', false)
            ->assertSee('Яндекс Браузере')
            ->assertSee(CardCertHint::GUIDE_URL, false);
    }

    public function test_checkout_has_no_hint_when_flag_off(): void
    {
        config(['features.checkout_card_cert_hint' => false]);

        $this->actingAs(User::factory()->create())
            ->get(route('checkout.show', $this->tariff()))
            ->assertOk()
            ->assertDontSee('data-testid="card-cert-hint"', false);
    }

    public function test_tg_payment_draft_carries_hint_only_with_flag(): void
    {
        Queue::fake();
        config(['features.telegram_zapisi_bot' => true]);
        Carbon::setTestNow('2026-09-16 12:00:00');

        $group = Group::create(['name' => 'Грамматика гр.61', 'telegram_chat_id' => '-100777']);
        foreach ([7 => '2026-09-07', 8 => '2026-09-14', 9 => '2026-09-21'] as $n => $date) {
            Schedule::create([
                'title' => "Грамматика гр.61 (#{$n}, ".Carbon::parse($date)->format('d.m.y').')',
                'start' => "{$date} 08:00:00",
                'end' => "{$date} 10:00:00",
                'group_id' => $group->id,
            ]);
        }

        config(['features.checkout_card_cert_hint' => true]);
        $on = app(SlotNoticeService::class)->paymentDraft($group, now());
        $this->assertStringContainsString('Яндекс Браузере', $on['text']);
        $this->assertStringContainsString('Оплатить в личном кабинете', $on['text']);

        config(['features.checkout_card_cert_hint' => false]);
        $off = app(SlotNoticeService::class)->paymentDraft($group, now());
        $this->assertStringNotContainsString('Яндекс Браузере', $off['text']);
    }
}
