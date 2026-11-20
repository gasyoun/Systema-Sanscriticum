<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Bot\BotKnowledgeBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H8473 — плашка «Карта не проходит…» после перехода НСПК на сертификаты
 * Минцифры (19-11-2026): витрина /online и вкладка «Мои оплаты» кабинета,
 * за тем же флагом features.checkout_card_cert_hint, что и чекаут-подсказка
 * PR #4325 (App\Support\CardCertHint). Тот же ответ — в базе ИИ-куратора
 * (BotKnowledgeBase::persona), рулинг MG 19-11 (пост 709 в @samskrte).
 */
class CardCertBannerTest extends TestCase
{
    use RefreshDatabase;

    private const SUBSTRING = 'Карта не проходит или страница не открывается';

    public function test_vitrina_online_shows_banner_when_flag_on(): void
    {
        config(['features.checkout_card_cert_hint' => true]);

        $this->get(route('shop.index'))
            ->assertOk()
            ->assertSee('data-testid="card-cert-banner"', false)
            ->assertSee(self::SUBSTRING)
            ->assertSee('https://browser.yandex.ru', false)
            ->assertSee('https://www.gosuslugi.ru/landing/crt', false);
    }

    public function test_vitrina_online_has_no_banner_when_flag_off(): void
    {
        config(['features.checkout_card_cert_hint' => false]);

        $this->get(route('shop.index'))
            ->assertOk()
            ->assertDontSee('data-testid="card-cert-banner"', false)
            ->assertDontSee(self::SUBSTRING);
    }

    public function test_cabinet_payments_tab_shows_banner_when_flag_on(): void
    {
        config(['features.checkout_card_cert_hint' => true]);

        $this->actingAs(User::factory()->create())
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('data-testid="card-cert-banner"', false)
            ->assertSee(self::SUBSTRING);
    }

    public function test_cabinet_payments_tab_has_no_banner_when_flag_off(): void
    {
        config(['features.checkout_card_cert_hint' => false]);

        $this->actingAs(User::factory()->create())
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertDontSee('data-testid="card-cert-banner"', false);
    }

    public function test_bot_knowledge_base_carries_the_same_answer(): void
    {
        $persona = app(BotKnowledgeBase::class)->persona();

        $this->assertStringContainsString('сертификаты Минцифры', $persona);
        $this->assertStringContainsString('browser.yandex.ru', $persona);
        $this->assertStringContainsString('gosuslugi.ru/landing/crt', $persona);
        $this->assertStringContainsString('СБП-переводом через куратора', $persona);
    }
}
