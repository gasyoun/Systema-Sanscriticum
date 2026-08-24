<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DonationGratitude;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Реестр благодарностей меценатам (план института N3).
 *
 * Инварианты:
 *  - строка реестра появляется ТОЛЬКО при явном согласии донора и только
 *    после фактической оплаты (paid-переход);
 *  - повторный paid-путь не плодит строки (уникальный payment_id);
 *  - на странице видны только публичные строки (включая офлайн-записи);
 *  - суммы нигде не показываются — их нет даже в схеме.
 */
class InstituteGratitudesTest extends TestCase
{
    use RefreshDatabase;

    private function donateWithConsent(string $email = 'donor@example.test', ?string $gratName = 'Анонимный филолог'): Payment
    {
        config(['institute.donations_enabled' => true]);

        Http::fake([
            'enter.tochka.com/*' => Http::response([
                'Data' => [
                    'paymentLink' => 'https://pay.tochka.com/redirect/g1',
                    'paymentLinkId' => 'tochka_g_001',
                ],
            ], 200),
        ]);

        $this->post(route('institute.donate'), array_filter([
            'amount' => 1000,
            'name' => 'Донор',
            'email' => $email,
            'gratitude_name' => $gratName,
            'gratitude_consent' => $gratName === null ? null : '1',
        ], fn ($v) => $v !== null));

        return Payment::query()->where('tariff', 'donation')->latest()->firstOrFail();
    }

    public function test_consent_creates_public_gratitude_only_after_paid(): void
    {
        $payment = $this->donateWithConsent();

        // Пока платёж pending — в реестре пусто.
        $this->assertSame(0, DonationGratitude::count());

        // Имитируем APPROVED-вебхук Точки.
        $payment->update(['status' => 'paid']);

        $row = DonationGratitude::firstOrFail();
        $this->assertSame($payment->id, $row->payment_id);
        $this->assertSame('Анонимный филолог', $row->name_display);
        $this->assertTrue($row->is_public);

        $this->get('/mecenaty')
            ->assertOk()
            ->assertSee('Благодарности меценатам')
            ->assertSee('Анонимный филолог');
    }

    public function test_amount_shown_only_on_separate_request(): void
    {
        // Согласие + отдельная просьба показать сумму.
        Http::fake(['enter.tochka.com/*' => Http::response([
            'Data' => ['paymentLink' => 'https://pay.tochka.com/r1', 'paymentLinkId' => 't_a'],
        ])]);
        config(['institute.donations_enabled' => true]);
        $this->post(route('institute.donate'), [
            'amount' => 2500,
            'name' => 'Иван',
            'email' => 'with-amount@example.test',
            'gratitude_name' => 'Открытый меценат',
            'gratitude_consent' => '1',
            'gratitude_amount' => '1',
        ]);
        Payment::query()->where('tariff', 'donation')->latest()->firstOrFail()->update(['status' => 'paid']);

        // Только согласие, без просьбы о сумме — вторым, уже другим гостем.
        $this->app['auth']->guard()->logout();
        Http::fake(['enter.tochka.com/*' => Http::response([
            'Data' => ['paymentLink' => 'https://pay.tochka.com/r2', 'paymentLinkId' => 't_b'],
        ])]);
        $this->post(route('institute.donate'), [
            'amount' => 900,
            'name' => 'Пётр',
            'email' => 'quiet@example.test',
            'gratitude_name' => 'Скромный меценат',
            'gratitude_consent' => '1',
        ]);
        $secondId = (int) Payment::query()->where('tariff', 'donation')->max('id');
        $this->assertGreaterThan(0, $secondId);
        Payment::findOrFail($secondId)->update(['status' => 'paid']);

        $this->assertTrue(DonationGratitude::query()->where('name_display', 'Открытый меценат')->sole()->show_amount);
        $this->assertFalse(DonationGratitude::query()->where('name_display', 'Скромный меценат')->sole()->show_amount);

        $html = $this->get('/mecenaty')->assertOk()->getContent();
        $this->assertStringContainsString('Открытый меценат', $html);
        $this->assertStringContainsString('2 500', $html);
        $this->assertStringContainsString('Скромный меценат', $html);
        $this->assertStringNotContainsString('— 900', $html);
    }

    public function test_without_consent_nothing_is_registered(): void
    {
        $payment = $this->donateWithConsent(gratName: null);
        $payment->update(['status' => 'paid']);

        $this->assertSame(0, DonationGratitude::count());

        $this->get('/mecenaty')->assertOk()->assertDontSee('Благодарности меценатам');
    }

    public function test_consent_without_name_ignored(): void
    {
        config(['institute.donations_enabled' => true]);

        Http::fake([
            'enter.tochka.com/*' => Http::response([
                'Data' => ['paymentLink' => 'https://pay.tochka.com/redirect/g2', 'paymentLinkId' => 't2'],
            ], 200),
        ]);

        $this->post(route('institute.donate'), [
            'amount' => 500,
            'name' => 'Донор',
            'email' => 'noname@example.test',
            'gratitude_consent' => '1',
            // имя не заполнено — согласие без имени не создаёт строку
        ]);

        $payment = Payment::query()->where('tariff', 'donation')->latest()->firstOrFail();
        $payment->update(['status' => 'paid']);

        $this->assertSame(0, DonationGratitude::count());
    }

    public function test_replayed_paid_transition_does_not_duplicate(): void
    {
        $payment = $this->donateWithConsent();
        $payment->update(['status' => 'paid']);

        // Повторный прогон процесса (переигранная доставка/ручной вызов) — по-прежнему одна строка.
        $payment->fresh()->processDonationGratitude();
        $payment->fresh()->processDonationGratitude();

        $this->assertSame(1, DonationGratitude::count());
    }

    public function test_page_shows_public_rows_only_including_offline(): void
    {
        DonationGratitude::create(['name_display' => 'Офлайн-меценат', 'is_public' => true]);
        DonationGratitude::create(['name_display' => 'Скрытый меценат', 'is_public' => false]);

        $this->get('/mecenaty')
            ->assertOk()
            ->assertSee('Офлайн-меценат')
            ->assertDontSee('Скрытый меценат');
    }

    public function test_hidden_online_donation_not_shown(): void
    {
        $user = User::factory()->create();
        $payment = Payment::create([
            'user_id' => $user->id,
            'amount' => 700,
            'tariff' => 'donation',
            'status' => 'pending',
            'claim_meta' => ['gratitude' => ['consent' => true, 'name' => 'Тихий благотворитель']],
        ]);
        $payment->update(['status' => 'paid']);

        $row = DonationGratitude::firstOrFail();
        $row->update(['is_public' => false]);

        $this->get('/mecenaty')
            ->assertOk()
            ->assertDontSee('Тихий благотворитель');
    }
}
