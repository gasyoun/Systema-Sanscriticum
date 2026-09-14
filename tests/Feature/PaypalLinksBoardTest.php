<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\Schedule;
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

    public function test_course_started_before_cut_off_is_hidden(): void
    {
        $old = Course::factory()->create([
            'title' => 'Старый поток до июля',
            'is_active' => true,
            'is_completed' => false,
        ]);
        Schedule::create([
            'title' => 'занятие',
            'start' => '2026-05-10 13:00:00',
            'end' => '2026-05-10 15:00:00',
            'course_id' => $old->id,
        ]);

        $new = Course::factory()->create([
            'title' => 'Новый поток июля',
            'is_active' => true,
            'is_completed' => false,
        ]);
        Schedule::create([
            'title' => 'занятие',
            'start' => '2026-07-05 13:00:00',
            'end' => '2026-07-05 15:00:00',
            'course_id' => $new->id,
        ]);
        Tariff::factory()->for($new)->create(['title' => 'Блок 1', 'price' => 5000, 'is_active' => true]);
        $new2 = Course::factory()->create([
            'title' => 'Второй новый поток июля',
            'is_active' => true,
            'is_completed' => false,
        ]);
        Tariff::factory()->for($new2)->create(['title' => 'Блок 1', 'price' => 6000, 'is_active' => true]);

        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $this->actingAs($admin)
            ->get('/admin/paypal-links')
            ->assertSuccessful()
            ->assertDontSee('Старый поток до июля', false)
            ->assertSee('Новый поток июля', false)
            ->assertSee('Оглавление', false);
    }

    public function test_course_without_schedule_still_listed(): void
    {
        $course = Course::factory()->create([
            'title' => 'Курс без расписания ещё',
            'is_active' => true,
            'is_completed' => false,
        ]);
        Tariff::factory()->for($course)->create([
            'title' => 'Блок 1',
            'price' => 5000,
            'is_active' => true,
        ]);

        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $this->actingAs($admin)
            ->get('/admin/paypal-links')
            ->assertSuccessful()
            ->assertSee('Курс без расписания ещё', false);
    }

    public function test_course_start_via_group_schedule_hidden(): void
    {
        $course = Course::factory()->create([
            'title' => 'Старый через группу',
            'is_active' => true,
            'is_completed' => false,
        ]);
        $group = Group::factory()->create();
        $course->groups()->attach($group->id);
        Schedule::create([
            'title' => 'занятие',
            'start' => '2026-03-01 10:00:00',
            'end' => '2026-03-01 12:00:00',
            'group_id' => $group->id,
        ]);

        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $this->actingAs($admin)
            ->get('/admin/paypal-links')
            ->assertSuccessful()
            ->assertDontSee('Старый через группу', false);
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
