<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

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
 * «Финансы» (/admin/payments) не должны предлагать бесконечную страницу.
 *
 * 25.11.2026 страница стабильно отвечала 500: админ когда-то выбрал в селекторе
 * «все», Filament запомнил это в сессии (`tables.<md5(ListPayments)>_per_page`),
 * и каждый следующий заход рендерил все ~9 900 платежей — PHP-FPM с
 * memory_limit 128M падал на «Allowed memory size … exhausted». Сменить размер
 * страницы из интерфейса было нельзя: страница умирала до отрисовки селектора.
 *
 * Пин держит обе половины: (1) «все» больше не предлагается, (2) застрявшее в
 * сессии `all` само вычищается валидацией Filament — страница открывается.
 */
class PaymentListPageSizeTest extends TestCase
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

    private function payment(): Payment
    {
        return Payment::create([
            'user_id' => User::factory()->create()->id,
            'course_id' => Course::factory()->create()->id,
            'amount' => 5000,
            'tariff' => 'full',
            'status' => 'paid',
        ]);
    }

    private static function perPageSessionKey(): string
    {
        return 'tables.'.md5(ListPayments::class).'_per_page';
    }

    /** @test */
    public function the_table_never_offers_an_unbounded_page(): void
    {
        $this->payment();

        $component = Livewire::test(ListPayments::class)->assertOk();
        $options = $component->instance()->getTable()->getPaginationPageOptions();

        $this->assertNotContains('all', $options, '«все» на 9 900 платежей = 500 по памяти');
        foreach ($options as $option) {
            $this->assertIsInt($option);
            $this->assertLessThanOrEqual(100, $option);
        }
    }

    /** @test */
    public function a_stale_all_page_size_in_the_session_does_not_break_the_page(): void
    {
        $this->payment();

        session()->put(self::perPageSessionKey(), 'all');

        $component = Livewire::test(ListPayments::class)->assertOk();

        $perPage = $component->instance()->getTableRecordsPerPage();
        $this->assertIsInt($perPage);
        $this->assertLessThanOrEqual(100, $perPage);
        $this->assertSame(null, session()->get(self::perPageSessionKey()), 'Filament вычищает невалидный размер страницы');
    }
}
