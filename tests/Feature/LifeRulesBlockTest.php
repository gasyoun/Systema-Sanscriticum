<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LandingPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LifeRulesBlockTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function block_renders_title_epigraph_and_all_rules_in_order(): void
    {
        $landing = LandingPage::create([
            'title' => 'Лендинг с манифестом',
            'slug' => 'life-rules-landing',
            'is_active' => true,
            'content' => [[
                'type' => 'life_rules_block',
                'data' => [
                    'title' => 'Жизненные правила для санскритологов',
                    'epigraph' => 'По образцу Роберта Шумана (1848).',
                    'byline' => 'Dr. Mārcis Gasūns',
                    'collapsed_count' => 2,
                    'items' => [
                        ['text' => 'Первое правило.'],
                        ['text' => 'Второе правило.'],
                        ['text' => 'Третье правило.'],
                    ],
                ],
            ]],
        ]);

        $response = $this->get('/'.$landing->slug);

        $response->assertOk();
        $response->assertSeeInOrder([
            'Жизненные правила для санскритологов',
            'По образцу Роберта Шумана (1848).',
            'Первое правило.',
            'Второе правило.',
            'Третье правило.',
            'Dr. Mārcis Gasūns',
        ]);
        $response->assertSee('Показать все 3 правил');
    }

    /** @test */
    public function block_without_items_renders_nothing_and_does_not_error(): void
    {
        $landing = LandingPage::create([
            'title' => 'Пустой манифест',
            'slug' => 'life-rules-empty',
            'is_active' => true,
            'content' => [[
                'type' => 'life_rules_block',
                'data' => [
                    'title' => 'Жизненные правила',
                    'items' => [],
                ],
            ]],
        ]);

        $response = $this->get('/'.$landing->slug);

        $response->assertOk();
        $response->assertDontSee('Жизненные правила для санскритологов');
    }

    /** @test */
    public function items_with_blank_text_are_skipped_and_does_not_error(): void
    {
        $landing = LandingPage::create([
            'title' => 'Битый манифест',
            'slug' => 'life-rules-broken-item',
            'is_active' => true,
            'content' => [[
                'type' => 'life_rules_block',
                'data' => [
                    'title' => 'Жизненные правила',
                    'items' => [
                        ['text' => ''],
                        ['text' => 'Живое правило.'],
                    ],
                ],
            ]],
        ]);

        $response = $this->get('/'.$landing->slug);

        $response->assertOk();
        $response->assertSee('Живое правило.');
    }

    /** @test */
    public function default_collapsed_count_is_seven_when_not_configured(): void
    {
        $items = collect(range(1, 9))->map(fn ($n) => ['text' => "Правило номер {$n}."])->all();

        $landing = LandingPage::create([
            'title' => 'Девять правил',
            'slug' => 'life-rules-nine',
            'is_active' => true,
            'content' => [[
                'type' => 'life_rules_block',
                'data' => [
                    'title' => 'Жизненные правила',
                    'items' => $items,
                ],
            ]],
        ]);

        $response = $this->get('/'.$landing->slug);

        $response->assertOk();
        $response->assertSee('Показать все 9 правил');
    }
}
