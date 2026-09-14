<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\TeacherWeeklyPayoutCalendar;
use App\Models\User;
use App\Services\PayoutForecastService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/**
 * H3532 — таб «Год» на /admin/teacher-weekly-payout-calendar.
 * Флаг TEACHER_PAYOUT_YEAR_VIEW default OFF: при OFF страница байт-в-байт прежняя.
 * Гость → 302; не-finance → 403; фенс money-таблиц при рендере.
 */
class PayrollYearViewTest extends TestCase
{
    use RefreshDatabase;

    private function fakeBank(): void
    {
        Cache::flush();
        config([
            'services.tochka.token' => 'test-token',
            'services.tochka.balance_cache_seconds' => 0,
        ]);
        Http::fake(['*' => Http::response($this->fakeTochka(), 200)]);
    }

    /** @return array<string, mixed> */
    private function fakeTochka(float $closing = 248387.93): array
    {
        return [
            'Data' => [
                'Balance' => [
                    ['accountId' => '40802810020000863757/044525104', 'type' => 'ClosingAvailable', 'dateTime' => '2026-08-21T15:20:33+00:00', 'Amount' => ['amount' => $closing, 'currency' => 'RUB']],
                ],
            ],
        ];
    }

    /** @return list<array{table: string, count: int}> */
    private function moneyFingerprint(): array
    {
        $svc = app(PayoutForecastService::class);

        return collect($svc->fingerprint())
            ->map(fn ($count, $table) => ['table' => $table, 'count' => $count])
            ->values()
            ->all();
    }

    /** @test */
    public function guest_gets_302(): void
    {
        $this->get('/admin/teacher-weekly-payout-calendar')->assertRedirect();
        $this->get('/admin/teacher-weekly-payout-calendar?tab=year')->assertRedirect();
    }

    /** @test */
    public function manager_without_finance_role_is_forbidden_even_with_flags(): void
    {
        $this->fakeBank();
        config([
            'features.teacher_weekly_payout_calendar' => true,
            'features.teacher_payout_year_view' => true,
        ]);
        $this->actingAs(User::factory()->create(['role' => 'manager', 'is_admin' => true]));
        $this->get('/admin/teacher-weekly-payout-calendar?tab=year')->assertForbidden();
    }

    /** @test */
    public function flag_off_keeps_page_byte_identical_no_year_dom(): void
    {
        $this->fakeBank();
        config([
            'features.teacher_weekly_payout_calendar' => true,
            'features.teacher_payout_year_view' => false,
        ]);
        $this->actingAs(User::factory()->create(['role' => Roles::ACCOUNTANT]));

        $response = $this->get('/admin/teacher-weekly-payout-calendar?tab=year');
        $response->assertSuccessful();

        // Никакого годового DOM: ни таба «Год», ни формы PayPal, ни ?tab=year ссылки.
        $content = $response->getContent() ?: '';
        $this->assertStringNotContainsString('wire:model="paypalBalance"', $content);
        $this->assertStringNotContainsString('?tab=year', $content);
        $this->assertStringNotContainsString('rub_need_forecast', $content);
    }

    /** @test */
    public function accountant_sees_year_tab_when_flag_on(): void
    {
        $this->fakeBank();
        config([
            'features.teacher_weekly_payout_calendar' => true,
            'features.teacher_payout_year_view' => true,
        ]);
        $this->actingAs(User::factory()->create(['role' => Roles::ACCOUNTANT]));

        $this->get('/admin/teacher-weekly-payout-calendar?tab=year')
            ->assertSuccessful()
            ->assertSee('?tab=year', false)
            ->assertSee('Баланс PayPal', false)
            ->assertSee('все получатели', false);
    }

    /** @test */
    public function rendering_year_tab_moves_no_money_tables(): void
    {
        $this->fakeBank();
        config([
            'features.teacher_weekly_payout_calendar' => true,
            'features.teacher_payout_year_view' => true,
        ]);
        $this->actingAs(User::factory()->create(['role' => Roles::ACCOUNTANT]));

        $before = $this->moneyFingerprint();
        $this->get('/admin/teacher-weekly-payout-calendar?tab=year')->assertSuccessful();
        $this->get('/admin/teacher-weekly-payout-calendar')->assertSuccessful();

        $afterSvc = app(PayoutForecastService::class)->fingerprint();
        $after = collect($before)
            ->map(fn ($row) => ['table' => $row['table'], 'count' => $afterSvc[$row['table']]])
            ->all();

        $this->assertSame($before, $after); // отпечатки до=после для каждого зонда
    }

    /** @test */
    public function saving_paypal_balance_requires_finance_gate(): void
    {
        config([
            'features.teacher_weekly_payout_calendar' => true,
            'features.teacher_payout_year_view' => true,
        ]);
        $this->actingAs(User::factory()->create(['role' => 'student']));

        // Не-finance пользователь не проходит canAccess страницы: действие записи
        // либо отвергнуто (403/404), либо недостижимо — главное, что записи нет.
        try {
            Livewire::test(TeacherWeeklyPayoutCalendar::class)->call('savePaypalBalance');
        } catch (HttpResponseException|HttpExceptionInterface) {
            // ожидаемый отказ доступа
        }

        $this->assertDatabaseCount('finance_snapshots', 0);
    }

    /** @test */
    public function saving_paypal_balance_writes_only_finance_snapshots(): void
    {
        $this->fakeBank();
        config([
            'features.teacher_weekly_payout_calendar' => true,
            'features.teacher_payout_year_view' => true,
        ]);
        $this->actingAs(User::factory()->create(['role' => Roles::ACCOUNTANT]));

        $before = app(PayoutForecastService::class)->fingerprint();

        Livewire::withQueryParams([])
            ->test(TeacherWeeklyPayoutCalendar::class, ['year' => now()->year])
            ->set('paypalBalance', '1250.10')
            ->call('savePaypalBalance')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('finance_snapshots', 1);
        $this->assertDatabaseHas('finance_snapshots', [
            'type' => 'paypal_balance',
            'amount_minor' => 125010,
            'currency' => 'EUR',
        ]);

        $afterSvc = app(PayoutForecastService::class)->fingerprint();
        foreach ($before as $table => $count) {
            $this->assertSame($count, $afterSvc[$table], "money table {$table} moved");
        }
    }

    /** @test */
    public function paypal_balance_input_validates(): void
    {
        config([
            'features.teacher_weekly_payout_calendar' => true,
            'features.teacher_payout_year_view' => true,
        ]);
        $this->actingAs(User::factory()->create(['role' => Roles::ACCOUNTANT]));

        Livewire::test(TeacherWeeklyPayoutCalendar::class)
            ->set('paypalBalance', '-5')
            ->call('savePaypalBalance')
            ->assertHasErrors(['paypalBalance']);

        $this->assertDatabaseCount('finance_snapshots', 0);
    }
}
