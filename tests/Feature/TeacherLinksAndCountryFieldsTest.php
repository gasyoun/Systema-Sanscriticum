<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\PaypalLinksBoard;
use App\Models\Course;
use App\Models\Tariff;
use App\Models\User;
use App\Support\PhoneCountrySuggest;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H3909 (MG 02-09-2026): (1) доска «Ссылки PayPal» доступна преподавателю —
 * он тоже отвечает ученикам «сколько и куда платить»; (2) у ученика есть
 * поля «Город» и «Страна» (спрашиваем у каждого, по ним куратор переименовывает
 * карточку по правилу «Имя, Город, Страна» и понимает канал оплаты).
 */
class TeacherLinksAndCountryFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_can_access_paypal_links_board(): void
    {
        $course = Course::factory()->create();
        Tariff::factory()->for($course)->create(['is_active' => true]);

        $teacher = User::factory()->create(['role' => Roles::TEACHER]);
        $this->actingAs($teacher)->get('/admin/paypal-links')->assertSuccessful();
        $this->assertTrue(PaypalLinksBoard::canAccess());
    }

    public function test_user_city_and_country_columns_exist_and_fillable(): void
    {
        $user = User::factory()->create();
        $user->update(['city' => 'Гренхен', 'country' => 'Швейцария']);
        $fresh = $user->fresh();
        $this->assertSame('Гренхен', $fresh->city);
        $this->assertSame('Швейцария', $fresh->country);
    }

    public function test_user_resource_form_has_city_and_country_fields(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $student = User::factory()->create();
        // Filament v3 рендерит поля без name-атрибута (wire:model + snapshot);
        // присутствие полей проверяем по лейблам.
        $this->actingAs($admin)
            ->get('/admin/users/'.$student->id.'/edit')
            ->assertSuccessful()
            ->assertSee('Город', false)
            ->assertSee('Страна', false);
    }

    public function test_country_is_proposed_from_phone_code(): void
    {
        $this->assertSame('Швейцария', PhoneCountrySuggest::fromPhone('+41 79 123 45 67'));
        $this->assertSame('Латвия', PhoneCountrySuggest::fromPhone('003712345678'));
        $this->assertSame('Украина', PhoneCountrySuggest::fromPhone('+380501234567'));
        // Российский транк «8» + 9xx → Россия; сотни РФ-учеников против
        // единиц швейцарцев, ложных срабатываний нет.
        $this->assertSame('Россия', PhoneCountrySuggest::fromPhone('8 913 123 45 67'));
        // +7 → Россия по умолчанию (MG 02-09-2026; Казахстан правится руками).
        $this->assertSame('Россия', PhoneCountrySuggest::fromPhone('+7 913 123 45 67'));
        $this->assertNull(PhoneCountrySuggest::fromPhone(null));
    }
}
