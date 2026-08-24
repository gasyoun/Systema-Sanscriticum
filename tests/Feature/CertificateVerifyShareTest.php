<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CertificateVerifyShareTest extends TestCase
{
    use RefreshDatabase;

    private function certificate(array $attrs = []): Certificate
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();

        return Certificate::create(array_merge([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'student_name' => 'Иван Петров',
            'course_title' => 'Основы санскрита',
        ], $attrs));
    }

    /** @test */
    public function verified_page_carries_og_meta_and_share_links(): void
    {
        $cert = $this->certificate();

        $this->get('/verify/'.$cert->number)
            ->assertOk()
            ->assertSee('property="og:title"', false)
            ->assertSee('Сертификат подтверждён: Иван Петров', false)
            ->assertSee('og:image', false)
            ->assertSee('https://t.me/share/url', false)
            ->assertSee('https://vk.com/share.php', false)
            ->assertSee('data-copy-url', false);
    }

    /** @test */
    public function spravka_wording_reaches_og_title(): void
    {
        $cert = $this->certificate(['document_type' => 'spravka']);

        $response = $this->get('/verify/'.$cert->number);
        $response->assertOk()
            ->assertSee('Справка подтверждена: Иван Петров', false);
        $this->assertStringContainsString('Моя справка Общества ревнителей санскрита', urldecode($response->getContent()));
    }

    /** @test */
    public function not_found_page_has_no_share_block(): void
    {
        $this->get('/verify/2099-ZZZZZ')
            ->assertOk()
            ->assertSee('Сертификат не найден')
            ->assertDontSee('t.me/share/url', false)
            ->assertDontSee('data-copy-url', false);
    }
}
