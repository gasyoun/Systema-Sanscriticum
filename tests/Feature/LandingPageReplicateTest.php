<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\LandingPageResource\Pages\ListLandingPages;
use App\Models\LandingBot;
use App\Models\LandingPage;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LandingPageReplicateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $admin = User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]);
        $this->actingAs($admin);
    }

    private function landing(array $attrs = []): LandingPage
    {
        return LandingPage::create(array_merge([
            'title' => 'Вебинар по грамматике',
            'slug' => 'webinar-grammatika',
            'is_active' => true,
            'is_listed' => true,
            'webinar_label' => '17 июня, 18:00 МСК',
            'content' => [
                ['type' => 'hero_block', 'data' => ['heading' => 'Намасте']],
                ['type' => 'price_block', 'data' => ['price' => '4 800 ₽']],
            ],
        ], $attrs));
    }

    /** @test */
    public function replicate_copies_all_settings_but_creates_the_copy_unpublished(): void
    {
        $page = $this->landing();

        Livewire::test(ListLandingPages::class)
            ->callTableAction('replicate', $page, data: [
                'title' => 'Вебинар по грамматике (копия)',
                'slug' => 'webinar-grammatika-copy',
            ])
            ->assertHasNoTableActionErrors();

        $copy = LandingPage::where('slug', 'webinar-grammatika-copy')->first();

        $this->assertNotNull($copy);
        $this->assertNotSame($page->id, $copy->id);
        $this->assertSame('Вебинар по грамматике (копия)', $copy->title);
        $this->assertEquals($page->content, $copy->content, 'Блоки контента копируются как есть.');
        $this->assertSame($page->webinar_label, $copy->webinar_label);
        $this->assertFalse($copy->is_active, 'Копия создаётся неопубликованной.');
        $this->assertFalse($copy->is_listed, 'Копия не должна сразу попасть в каталог/sitemap.');
        // Оригинал не тронут.
        $this->assertTrue($page->fresh()->is_active);
    }

    /** @test */
    public function replicate_rejects_an_already_taken_slug(): void
    {
        $page = $this->landing();
        $this->landing(['title' => 'Другой', 'slug' => 'zanyat']);

        Livewire::test(ListLandingPages::class)
            ->callTableAction('replicate', $page, data: [
                'title' => 'Копия',
                'slug' => 'zanyat',
            ])
            ->assertHasTableActionErrors(['slug']);

        $this->assertSame(2, LandingPage::count(), 'Копия при коллизии слага не создаётся.');
    }

    /** @test */
    public function replicate_does_not_clone_the_landing_bot(): void
    {
        // landing_bots: unique landing_page_id + webhook_key — клон запрещён схемой.
        $page = $this->landing();
        LandingBot::create([
            'landing_page_id' => $page->id,
            'channel' => 'telegram',
            'webhook_key' => 'secret-key-1',
        ]);

        Livewire::test(ListLandingPages::class)
            ->callTableAction('replicate', $page, data: [
                'title' => 'Копия',
                'slug' => 'webinar-grammatika-copy',
            ])
            ->assertHasNoTableActionErrors();

        $copy = LandingPage::where('slug', 'webinar-grammatika-copy')->first();

        $this->assertNotNull($copy);
        $this->assertNull($copy->bot, 'Бот привязан к оригиналу и не клонируется.');
        $this->assertNotNull($page->fresh()->bot);
    }
}
