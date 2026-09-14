<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Widgets\TochkaClosingAvailableWidget;
use App\Models\Payment;
use App\Models\TeacherPayout;
use App\Models\User;
use App\Services\Payments\TochkaBalanceService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TochkaBalanceWidgetTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function fakeBody(): array
    {
        return [
            'Data' => [
                'Balance' => [
                    ['accountId' => '40802810020000863757/044525104', 'type' => 'OpeningAvailable', 'dateTime' => '2026-08-21T15:20:33+00:00', 'Amount' => ['amount' => 146054.62, 'currency' => 'RUB']],
                    ['accountId' => '40802810020000863757/044525104', 'type' => 'ClosingAvailable', 'dateTime' => '2026-08-21T15:20:33+00:00', 'Amount' => ['amount' => 128849.8, 'currency' => 'RUB']],
                    ['accountId' => '40802810020000863757/044525104', 'type' => 'Expected', 'dateTime' => '2026-08-21T15:20:33+00:00', 'Amount' => ['amount' => 17204.82, 'currency' => 'RUB']],
                    ['accountId' => '40802810020000877617/044525104', 'type' => 'OpeningAvailable', 'dateTime' => '2026-08-21T15:20:33+00:00', 'Amount' => ['amount' => 119538.13, 'currency' => 'RUB']],
                    ['accountId' => '40802810020000877617/044525104', 'type' => 'ClosingAvailable', 'dateTime' => '2026-08-21T15:20:33+00:00', 'Amount' => ['amount' => 119538.13, 'currency' => 'RUB']],
                    ['accountId' => '40802810020000877617/044525104', 'type' => 'Expected', 'dateTime' => '2026-08-21T15:20:33+00:00', 'Amount' => ['amount' => 0.0, 'currency' => 'RUB']],
                ],
            ],
        ];
    }

    /** @test */
    public function widget_hidden_when_flag_off(): void
    {
        config(['features.tochka_balance_on_salaries' => false]);
        $this->actingAs(User::factory()->create(['role' => Roles::ACCOUNTANT]));
        $this->assertFalse(TochkaClosingAvailableWidget::canView());
    }

    /** @test */
    public function teacher_never_sees_bank_even_with_flag(): void
    {
        config(['features.tochka_balance_on_salaries' => true]);
        $this->actingAs(User::factory()->create(['role' => Roles::TEACHER]));
        $this->assertFalse(TochkaClosingAvailableWidget::canView());
    }

    /** @test */
    public function accountant_sees_widget_when_flag_on(): void
    {
        config(['features.tochka_balance_on_salaries' => true]);
        $this->actingAs(User::factory()->create(['role' => Roles::ACCOUNTANT]));
        $this->assertTrue(TochkaClosingAvailableWidget::canView());
    }

    /** @test */
    public function snapshot_sums_closing_available_and_does_not_touch_money_tables(): void
    {
        Cache::flush();
        config(['services.tochka.token' => 'test-token', 'services.tochka.balance_cache_seconds' => 0]);
        Http::fake([
            '*' => Http::response($this->fakeBody(), 200),
        ]);

        $payouts = TeacherPayout::query()->count();
        $payments = Payment::query()->count();

        $snap = app(TochkaBalanceService::class)->snapshot();

        $this->assertTrue($snap['ok']);
        $this->assertEquals(248387.93, $snap['closing_total']);
        $this->assertCount(2, $snap['accounts']);
        $this->assertSame(['863757', '877617'], array_column($snap['accounts'], 'tail'));
        $this->assertSame($payouts, TeacherPayout::query()->count());
        $this->assertSame($payments, Payment::query()->count());
    }

    /** @test */
    public function artisan_json_leaves_money_tables_unmoved(): void
    {
        Cache::flush();
        config(['services.tochka.token' => 'test-token', 'services.tochka.balance_cache_seconds' => 0]);
        Http::fake([
            '*' => Http::response($this->fakeBody(), 200),
        ]);

        $payouts = TeacherPayout::query()->count();
        $payments = Payment::query()->count();
        $this->artisan('tochka:balance', ['--json' => true])->assertSuccessful();
        $this->assertSame($payouts, TeacherPayout::query()->count());
        $this->assertSame($payments, Payment::query()->count());
    }
}
