<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\PaypalLinksBoard;
use App\Filament\Resources\UserResource;
use App\Models\Course;
use App\Models\Tariff;
use App\Models\User;
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
        $form = UserResource::form(
            \Filament\Forms\Form::make()->model(User::class)
        );
        $json = json_encode($form->getComponents(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('"city"', $json);
        $this->assertStringContainsString('"country"', $json);
        $this->assertStringContainsString('Страна', $json);
    }
}
