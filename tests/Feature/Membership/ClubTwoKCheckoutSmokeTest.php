<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Enums\MembershipTier;
use App\Models\ClubMembership;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Чекаут-смоук тира Клуб ₽2 000/мес (H5822, C2 правления MG 03-10-2026,
 * лист 0LV): проверить, что двухтысячный тир сквозной — конфиг цены,
 * лендинг с RU-копи, страница чекаута, заказ в Точку и выдача членства.
 *
 * Почему тест, а не «доклад»: рублёвый контур нельзя трогать на проде без
 * MG, а смоук нужен воспроизводимый. Здесь чекаут проходит на тестовой базе,
 * Точка подменена фейком (ни одного реального списания), прод-флаги
 * MEMBERSHIP_TIERED/CLUB_MEMBERSHIP остаются OFF — всё пользовательское
 * по-прежнему за флагом, финальный взгляд MG не заменён.
 *
 * Контракт цен — H3331 (шесть тарифов Basic/Club × 1/3/12 мес), клубная
 * строка: ₽2 000 / ₽5 700 (−5 %) / ₽20 400 (−15 %).
 */
final class ClubTwoKCheckoutSmokeTest extends MembershipTestCase
{
    /** Точка не дёргается по-настоящему — отдаём фейковую платёжную ссылку. */
    protected function setUp(): void
    {
        parent::setUp();

        // Тёмный деплой: тиры включаются ТОЛЬКО в тестовом окружении.
        config()->set('features.membership_tiered', true);

        Http::fake([
            'enter.tochka.com/*' => Http::response([
                'Data' => [
                    'paymentLink' => 'https://pay.tochka.com/redirect/club2k',
                    'paymentLinkId' => 'tochka_club_2k',
                ],
            ], 200),
        ]);
    }

    /** Шесть тарифов контракта H3331 на клубном курсе. */
    private function seedTieredTariffs(): void
    {
        foreach ([1, 3, 12] as $months) {
            $this->clubTariff($months, MembershipTier::Basic);
            $this->clubTariff($months, MembershipTier::Club);
        }
    }

    private function clubMonthlyTariff(): Tariff
    {
        return Tariff::query()
            ->where('course_id', $this->clubCourse->id)
            ->where('membership_tier', MembershipTier::Club)
            ->where('membership_months', 1)
            ->firstOrFail();
    }

    public function test_tier_config_carries_the_2k_club_price(): void
    {
        // Конфиг тира — единый источник цены, не хардкод в шаблоне.
        $this->assertSame(2000, (int) config('membership.tiers.club.monthly_price'));
        $this->assertSame(2000, MembershipTier::Club->monthlyPrice());

        // Клубная строка контракта H3331: 2000 / 5700 (−5 %) / 20400 (−15 %).
        $this->assertSame(2000, MembershipTier::Club->priceForTerm(1));
        $this->assertSame(5700, MembershipTier::Club->priceForTerm(3));
        $this->assertSame(20400, MembershipTier::Club->priceForTerm(12));

        // Базовая строка — сосед по контракту, чтобы смоук поймал перепутанные тиры.
        $this->assertSame(1000, MembershipTier::Basic->priceForTerm(1));
        $this->assertSame(2850, MembershipTier::Basic->priceForTerm(3));
        $this->assertSame(10200, MembershipTier::Basic->priceForTerm(12));
    }

    public function test_landing_and_checkout_render_the_2k_price_from_db(): void
    {
        $this->seedTieredTariffs();
        $clubMonthly = $this->clubMonthlyTariff();
        $this->assertSame(2000, (int) $clubMonthly->price);

        // RU-копи на лендинге: тир Клуб с ценой ₽2 000 видим, CTA живёт.
        $landing = $this->get('/klub')
            ->assertOk()
            ->assertSee('Клуб')
            ->assertSee('Базовый')
            ->assertSee('2 000');
        $landing->assertSee(route('checkout.show', $clubMonthly), false);

        // Страница чекаута этого тарифа рендерит ту же цену ₽2 000.
        $this->get(route('checkout.show', $clubMonthly))
            ->assertOk()
            ->assertSee('2 000');
    }

    public function test_checkout_creates_pending_payment_then_paid_grants_club_membership(): void
    {
        $this->seedTieredTariffs();
        $user = User::factory()->create();
        $clubMonthly = $this->clubMonthlyTariff();

        // Шаг 1 — чекаут: заказ ровно на ₽2 000, реального списания нет
        // (ссылка Точки фейковая), платёж висит pending.
        $this->actingAs($user)
            ->post(route('payment.create'), ['tariff_id' => $clubMonthly->id])
            ->assertRedirect('https://pay.tochka.com/redirect/club2k');

        $payment = Payment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $this->clubCourse->id)
            ->firstOrFail();
        $this->assertSame('pending', $payment->status);
        $this->assertEquals(2000, (float) $payment->amount);
        $this->assertSame('membership_club_1m', $payment->tariff);

        // Шаг 2 — вебхук Точки (pending -> paid) выдаёт членство тира Клуб
        // ровно на оплаченный месяц и заводит студента в клубную группу.
        $payment->update(['status' => 'paid']);

        $membership = ClubMembership::query()->where('payment_id', $payment->id)->firstOrFail();
        $this->assertSame(MembershipTier::Club, $membership->tier_code);
        $this->assertSame(1, $membership->term_months);
        $this->assertTrue($membership->isActive());
        $this->assertTrue(
            $user->fresh()->groups()->where('groups.id', $this->clubGroup->id)->exists(),
            'оплаченный тир ₽2 000 должен выдать клубную группу'
        );
    }
}
