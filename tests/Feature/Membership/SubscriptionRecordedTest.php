<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Enums\MembershipTier;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Schedule;
use App\Models\StudentDiscount;
use App\Models\Tariff;
use App\Models\User;
use App\Services\Membership\ClubMembershipService;
use Illuminate\Support\Carbon;

/**
 * H3916: подписка «в записи» — сетка A (MG 06-09-2026), 6-месячное окно,
 * скидка лояльности 5% первого года, лендинг.
 */
final class SubscriptionRecordedTest extends MembershipTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.recorded_subscription', true);
    }

    // --- Сетка A: тарифы ---

    public function test_price_grid_is_exact_for_subscription_tiers(): void
    {
        $this->assertSame(20000, MembershipTier::Standard->priceForTerm(12));
        $this->assertSame(5500, MembershipTier::Standard->priceForTerm(3));
        $this->assertSame(35000, MembershipTier::Professional->priceForTerm(12));
        // Обычная месячная математика тиров без term_prices не тронута.
        $this->assertSame(2000, MembershipTier::Club->priceForTerm(1));
    }

    public function test_install_command_creates_grid_and_retires_old_club_line(): void
    {
        $legacyMonthly = Tariff::factory()->create([
            'course_id' => $this->clubCourse->id,
            'title' => 'Клуб — месяц',
            'price' => 1500,
            'membership_months' => 1,
            'membership_tier' => MembershipTier::Club,
        ]);

        $this->artisan('membership:install-subscription-tariffs')->assertSuccessful();

        $keys = Tariff::query()
            ->where('course_id', $this->clubCourse->id)
            ->where('is_active', true)
            ->whereNotNull('membership_months')
            ->get()
            ->map(fn (Tariff $t) => $t->accessKey())
            ->all();

        $this->assertContains('membership_standard_3m', $keys);
        $this->assertContains('membership_standard_12m', $keys);
        $this->assertContains('membership_professional_12m', $keys);
        $this->assertNotContains('membership_club_1m', $keys);
        $this->assertFalse($legacyMonthly->refresh()->is_active, 'старая клубная линия 1500 ₽/мес снята с продажи');

        // Идемпотентность: второй прогон не плодит дубли.
        $this->artisan('membership:install-subscription-tariffs')->assertSuccessful();
        $this->assertSame(
            3,
            Tariff::query()->where('course_id', $this->clubCourse->id)->where('is_active', true)->count()
        );
    }

    public function test_payment_on_subscription_tariff_yields_period_and_tier(): void
    {
        $this->artisan('membership:install-subscription-tariffs')->assertSuccessful();

        $service = app(ClubMembershipService::class);
        $user = User::factory()->create();

        $standard = Tariff::query()->where('title', 'like', '%Стандарт (год)%')->firstOrFail();
        $payment = Payment::create([
            'user_id' => $user->id,
            'course_id' => $this->clubCourse->id,
            'amount' => (float) $standard->price,
            'tariff' => $standard->accessKey(),
            'status' => 'paid',
        ]);

        $this->assertSame(12, $service->termMonthsFor($payment));
        $this->assertSame(MembershipTier::Standard, $service->tierFor($payment));

        $professional = Tariff::query()->where('title', 'like', '%Профессионал%')->firstOrFail();
        $payment2 = Payment::create([
            'user_id' => $user->id,
            'course_id' => $this->clubCourse->id,
            'amount' => (float) $professional->price,
            'tariff' => $professional->accessKey(),
            'status' => 'paid',
        ]);
        $this->assertSame(MembershipTier::Professional, $service->tierFor($payment2));
    }

    // --- 6-месячное окно ---

    public function test_archive_refresh_joins_only_streams_past_six_months(): void
    {
        $old = Course::factory()->create(['format' => 'recorded', 'is_visible' => true, 'club_included' => false]);
        Schedule::create(['course_id' => $old->id, 'title' => 's', 'start' => Carbon::now()->subMonths(7)]);

        $fresh = Course::factory()->create(['format' => 'recorded', 'is_visible' => true, 'club_included' => false]);
        Schedule::create(['course_id' => $fresh->id, 'title' => 's', 'start' => Carbon::now()->subMonths(5)]);

        $noSchedule = Course::factory()->create(['format' => 'recorded', 'is_visible' => true, 'club_included' => false]);

        $live = Course::factory()->create(['format' => 'live', 'is_visible' => true, 'club_included' => false]);
        Schedule::create(['course_id' => $live->id, 'title' => 's', 'start' => Carbon::now()->subMonths(9)]);

        $this->artisan('subscription:refresh-archive')->assertSuccessful();

        $this->assertTrue((bool) $old->refresh()->club_included, 'поток старше 6 мес вошёл в архив');
        $this->assertNotNull($old->subscription_archive_joined_at);
        $this->assertFalse((bool) $fresh->refresh()->club_included, 'свежий поток остаётся эксклюзивным');
        $this->assertFalse((bool) $noSchedule->refresh()->club_included, 'без расписания окно не открывается');
        $this->assertFalse((bool) $live->refresh()->club_included, 'живой формат не входит в архив записей');
    }

    // --- Скидка лояльности 5% первого года ---

    public function test_loyalty_discount_expires_after_first_year(): void
    {
        $user = User::factory()->create();
        $tariff = Tariff::factory()->create([
            'course_id' => $this->clubCourse->id,
            'price' => 20000,
            'membership_months' => 12,
            'membership_tier' => MembershipTier::Standard,
        ]);

        StudentDiscount::create([
            'user_id' => $user->id,
            'course_id' => $this->clubCourse->id,
            'type' => StudentDiscount::TYPE_PERCENT,
            'value' => 5,
            'is_active' => true,
            'expires_at' => today()->addMonths(12),
            'note' => 'recorded-subscription loyalty (H3916, 5% first year)',
        ]);

        $this->assertSame(19000.0, $tariff->calculateFinalPriceForUser($user));

        // После истечения срока скидка мертва — цена полная.
        Carbon::setTestNow(today()->addMonths(12)->addDay());
        $this->assertSame(20000.0, $tariff->calculateFinalPriceForUser($user));
        Carbon::setTestNow();
    }

    public function test_seed_command_creates_and_is_idempotent(): void
    {
        $user = User::factory()->create(['email' => 'loyal@example.test']);
        $this->artisan('membership:seed-loyalty-discounts', [
            '--file' => $this->writeList(['loyal@example.test']),
            '--dry-run' => true,
        ])->assertSuccessful();
        $this->assertSame(0, StudentDiscount::count(), 'dry-run ничего не пишет');

        $this->artisan('membership:seed-loyalty-discounts', [
            '--file' => $this->writeList(['loyal@example.test']),
        ])->assertSuccessful();
        $this->assertSame(1, StudentDiscount::count());

        $this->artisan('membership:seed-loyalty-discounts', [
            '--file' => $this->writeList(['loyal@example.test']),
        ])->assertSuccessful();
        $this->assertSame(1, StudentDiscount::count(), 'повторный прогон не плодит дубли');

        $row = StudentDiscount::first();
        $this->assertSame(5, (int) $row->value);
        $this->assertNotNull($row->expires_at);
    }

    // --- Лендинг ---

    public function test_landing_is_dark_until_flag_is_on(): void
    {
        config()->set('features.recorded_subscription', false);
        $this->get('/podpiska-zapisi')->assertNotFound();

        Course::factory()->create(['format' => 'recorded', 'is_visible' => true, 'club_included' => true]);
        $this->artisan('membership:install-subscription-tariffs')->assertSuccessful();

        config()->set('features.recorded_subscription', true);
        $response = $this->get('/podpiska-zapisi');
        $response->assertOk();
        $response->assertSee('20 000', false);
        $response->assertSee('35 000', false);
        $response->assertSee('5 500', false);
        // Честный анкор: зачёркнутая сумма = сумме цен архива (только visible recorded courses).
        $response->assertSee('line-through', false);
        // Старая клубная линия на странице не продаётся.
        $response->assertDontSee('1 500', false);
    }

    /**
     * @param  list<string>  $lines
     */
    private function writeList(array $lines): string
    {
        $path = storage_path('framework/testing/h3916-loyalty-list.txt');
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, implode("\n", $lines));

        return $path;
    }
}
