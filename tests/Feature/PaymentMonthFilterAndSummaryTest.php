<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\PaymentResource\Pages\ListPayments;
use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «Финансы»: быстрый фильтр «Месяц» по created_at и итоговая строка по всему
 * отфильтрованному набору (не по странице): количество, сумма всего, разбивка
 * «Оплачено / Ожидает» — pending держит полную цену тарифа, поэтому в одну
 * цифру с оплаченным не смешивается.
 */
class PaymentMonthFilterAndSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]));
    }

    private function payment(float $amount, string $status, string $createdAt): Payment
    {
        return Payment::create([
            'user_id' => User::factory()->create()->id,
            'course_id' => Course::factory()->create()->id,
            'amount' => $amount,
            'tariff' => 'full',
            'status' => $status,
            'created_at' => $createdAt,
        ]);
    }

    /** @test */
    public function month_filter_leaves_only_payments_of_that_month(): void
    {
        $octoberPaid = $this->payment(5000, 'paid', '2026-10-05 12:00:00');
        $octoberPending = $this->payment(3000, 'pending', '2026-10-20 09:30:00');
        $september = $this->payment(7000, 'paid', '2026-09-15 10:00:00');

        Livewire::test(ListPayments::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$octoberPaid, $octoberPending, $september])
            ->filterTable('month', '2026-10')
            ->assertCanSeeTableRecords([$octoberPaid, $octoberPending])
            ->assertCanNotSeeTableRecords([$september])
            // Соседний месяц — пусто.
            ->filterTable('month', '2026-09')
            ->assertCanSeeTableRecords([$september])
            ->assertCanNotSeeTableRecords([$octoberPaid, $octoberPending]);
    }

    /** @test */
    public function summary_totals_cover_whole_filtered_set_and_split_by_status(): void
    {
        $this->payment(5000, 'paid', '2026-10-05 12:00:00');
        $this->payment(3000, 'pending', '2026-10-20 09:30:00');
        $this->payment(7000, 'paid', '2026-09-15 10:00:00');

        Livewire::test(ListPayments::class)
            ->assertOk()
            // Итоги считаются по всей таблице, а не по текущей странице.
            ->assertSee('Оплачено: 12 000 ₽ · Ожидает: 3 000 ₽')
            // Фильтр месяца перестраивает итоги.
            ->filterTable('month', '2026-10')
            ->assertSee('Оплачено: 5 000 ₽ · Ожидает: 3 000 ₽')
            ->assertDontSee('Оплачено: 12 000 ₽');
    }
}
