<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\MarketingSetting;
use App\Models\MessageTemplate;
use App\Models\SupportAnswerSuggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H7591: seeder binds exactly one active D-category template whose body
 * carries the payment links and no ruble amounts (MG ruling 01-11-2026).
 */
class SupportDTemplatePaymentLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_binds_one_active_d_template_with_links_no_amounts(): void
    {
        config(['features.support_answer_suggester' => true]);
        MarketingSetting::create(['support_answer_suggester_enabled' => true]);
        MarketingSetting::flushCached();

        $this->artisan('db:seed', ['--class' => MessageTemplateSeeder::class]);

        $bound = MessageTemplate::query()
            ->where('suggester_category', SupportAnswerSuggestion::CATEGORY_PAYMENT)
            ->where('is_active', true)
            ->get();

        $this->assertSame(1, $bound->count());
        $this->assertStringContainsString('samskrte.ru/online', $bound[0]->body);
        $this->assertStringContainsString('samskrtam.ru/u', $bound[0]->body);
        $this->assertStringNotContainsString('₽', $bound[0]->body);
    }
}
