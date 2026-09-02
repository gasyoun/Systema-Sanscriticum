<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Tariff;
use App\Models\TariffForeignPrice;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H3907 — админ-доска «Ссылки PayPal»: живые группы с активными тарифами,
 * у каждого тарифа кликабельная /paypal/{id} ссылка и фиксированные EUR/USD
 * цены из tariff_foreign_prices.
 */
class PaypalLinksBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_active_groups_with_paypal_links(): void
    {
        $course = Course::factory()->create([
            'title' => 'Грамматика хинди гр. 2, суббота 13:00 (2026)',
            'slug' => 'hindi-2-sb1300-2026',
            'is_active' => true,
            'is_completed' => false,
        ]);
        $tariff = Tariff::factory()->for($course)->create([
            'title' => 'Блок 4',
            'price' => 8000,
            'type' => 'block',
            'block_number' => 4,
            'is_active' => true,
        ]);
        TariffForeignPrice::create([
            'tariff_id' => $tariff->id,
            'currency' => 'EUR',
            'price' => 90,
            'fx_rate' => 100.3595,
            'computed_at' => now(),
        ]);

        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $this->actingAs($admin)
            ->get('/admin/paypal-links')
            ->assertSuccessful()
            ->assertSee('Грамматика хинди гр. 2, суббота 13:00 (2026)', false)
            ->assertSee('/paypal/'.$tariff->id, false)
            ->assertSee('90 €', false)
            ->assertSee('/course/hindi-2-sb1300-2026', false);
    }

    public function test_completed_course_is_not_listed(): void
    {
        Course::factory()->create([
            'title' => 'Завершённый курс уникальный',
            'is_active' => true,
            'is_completed' => true,
        ]);

        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $this->actingAs($admin)
            ->get('/admin/paypal-links')
            ->assertSuccessful()
            ->assertDontSee('Завершённый курс уникальный', false);
    }

    public function test_curator_and_teacher_can_access_and_student_cannot(): void
    {
        $curator = User::factory()->create(['role' => Roles::MANAGER]);
        $this->actingAs($curator)->get('/admin/paypal-links')->assertSuccessful();

        $teacher = User::factory()->create(['role' => Roles::TEACHER]);
        $this->actingAs($teacher)->get('/admin/paypal-links')->assertSuccessful();

        $student = User::factory()->create(['role' => null]);
        $this->actingAs($student)->get('/admin/paypal-links')->assertForbidden();
    }
}
