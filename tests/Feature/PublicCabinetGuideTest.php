<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\StudentCabinetGuideController;
use App\Models\User;
use App\Support\MarkdownGuide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Публичный гид кабинета /help/kabinet (H3499): страница для ещё НЕ вошедших.
 */
class PublicCabinetGuideTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_sees_public_guide_with_login_cta(): void
    {
        $this->get('/help/kabinet')
            ->assertOk()
            ->assertSee('Гид личного кабинета', false)
            ->assertSee(route('login'), false)
            ->assertSee(MarkdownGuide::SCREENSHOT_BASE.'student-guide/', false)
            ->assertDontSee('src="screenshots/', false);
    }

    public function test_public_page_renders_for_authed_user_too(): void
    {
        $student = User::factory()->create();

        $this->actingAs($student)
            ->get(route('help.cabinet-guide'))
            ->assertOk();
    }

    public function test_guide_source_points_to_the_public_copy_not_the_authed_one(): void
    {
        $path = base_path(StudentCabinetGuideController::SOURCE);

        $this->assertFileExists($path);

        $text = (string) file_get_contents($path);

        $this->assertStringNotContainsString(
            'samskrte.ru/dvaram/help',
            $text,
            'Гид ссылается на страницу за логином — публичной аудитории туда не попасть.'
        );
        $this->assertStringContainsString('https://samskrte.ru/help/kabinet', $text);
    }
}
