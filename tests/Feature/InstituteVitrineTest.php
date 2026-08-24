<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstituteVitrineTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_renders_with_brand_guards(): void
    {
        $this->get('/institut')
            ->assertOk()
            ->assertSee('Институт исследования санскрита')
            ->assertSee('30 000 ₽')
            ->assertSee('72 академических часа')
            ->assertDontSee('школа')
            ->assertDontSee('академия');
    }

    public function test_apply_creates_lead_with_role_and_utm(): void
    {
        $this->post('/institut/zayavka', [
            'name' => 'Тест Заявитель',
            'contact' => 'probe@example.com',
            'experience' => 'Преподаю йогу, читаю мантры',
            'is_promo_agreed' => '1',
            'website' => '',
            'utm_source' => 'vk',
        ])->assertRedirect(route('institute.landing'));

        $lead = Lead::query()->where('utm_source', 'vk')->first();
        $this->assertNotNull($lead);
        $this->assertSame('Тест Заявитель', $lead->name);
        $this->assertStringContainsString('Роль/опыт: Преподаю йогу', $lead->contact);
        $this->assertSame('probe@example.com', $lead->email);
    }

    public function test_honeypot_rejects_silently(): void
    {
        $this->post('/institut/zayavka', [
            'name' => 'Бот',
            'contact' => 'bot@example.com',
            'is_promo_agreed' => '1',
            'website' => 'http://spam.example',
        ])->assertSessionHasErrors('website');

        $this->assertSame(0, Lead::count());
    }

    public function test_requires_promo_consent(): void
    {
        $this->post('/institut/zayavka', [
            'name' => 'Без согласия',
            'contact' => 'x@example.com',
        ])->assertSessionHasErrors('is_promo_agreed');

        $this->assertSame(0, Lead::count());
    }
}
