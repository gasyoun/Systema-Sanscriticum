<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\AccountantGuide;
use App\Filament\Pages\PayoutAttributionGuide;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Механическая приёмка книги бухгалтера (H3214, волна 3).
 */
class AccountantCabinetGuideCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function guideText(): string
    {
        $path = AccountantGuide::sourcePath();

        $this->assertFileExists($path, 'Книга бухгалтера отсутствует: '.AccountantGuide::SOURCE);

        $text = (string) file_get_contents($path);

        $this->assertNotSame('', trim($text), 'Книга бухгалтера пуста.');

        return $text;
    }

    private function partOne(string $text): string
    {
        $start = mb_strpos($text, '# Часть I.');
        $end = mb_strpos($text, '# Часть II.');

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return mb_substr($text, $start, $end - $start);
    }

    public function test_guide_file_exists_and_has_four_parts_and_six_scenarios(): void
    {
        $text = $this->guideText();

        foreach (['Часть I', 'Часть II', 'Часть III', 'Часть IV'] as $part) {
            $this->assertStringContainsString($part, $text, "В книге нет раздела «{$part}».");
        }

        preg_match_all('/^### Шаги\s*$/mu', $this->partOne($text), $matches);
        $this->assertCount(6, $matches[0], 'В части I должно быть шесть сценариев (заголовок «### Шаги»).');

        $this->assertStringContainsString('/admin/payout-attribution-guide', $text);
        $this->assertStringContainsString('/admin/accountant-guide', $text);
        $this->assertStringContainsString('Как размечать выплаты', $text);
        $this->assertStringContainsString('не копирует', $text);
    }

    public function test_every_scenario_step_carries_a_reversibility_badge(): void
    {
        $partOne = $this->partOne($this->guideText());

        preg_match_all('/^\d+\.\s+(.+)$/mu', $partOne, $matches);

        $this->assertNotEmpty($matches[1], 'В части I не нашлось ни одного шага сценария.');

        $withoutBadge = [];

        foreach ($matches[1] as $step) {
            $hasBadge = str_contains($step, '**[Безопасно]**')
                || str_contains($step, '**[Видно студенту]**')
                || str_contains($step, '**[Необратимо]**');

            if (! $hasBadge) {
                $withoutBadge[] = mb_substr($step, 0, 80);
            }
        }

        $this->assertSame(
            [],
            $withoutBadge,
            "Шаги сценариев без пометки обратимости:\n  ".implode("\n  ", $withoutBadge)
        );
    }

    public function test_guide_does_not_leak_internal_names_or_live_fio(): void
    {
        $text = $this->guideText();
        $body = preg_replace('/\[[^\]]*\]\([^)]*\)/u', '', $text) ?? $text;

        $forbidden = [
            'canViewAny', 'canAccess', 'RoleGate', 'Resource::', 'Filament',
            'teacher_id', 'is_admin', 'php artisan', 'Str::markdown', '::class',
            'Ворошилов', 'Мария',
        ];

        foreach ($forbidden as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $body,
                "Внутреннее имя или живое ФИО «{$needle}» утекло в текст книги."
            );
        }
    }

    public function test_docs_screenshots_has_no_accountant_files(): void
    {
        $root = base_path('docs/screenshots');
        $this->assertDirectoryDoesNotExist($root.'/accountant');
        $this->assertDirectoryDoesNotExist($root.'/accountant-guide');

        $hits = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $name = mb_strtolower($file->getFilename());
            if (str_contains($name, 'accountant')) {
                $hits[] = $file->getPathname();
            }
        }

        $this->assertSame([], $hits, 'В docs/screenshots не должно быть accountant PNG.');
    }

    public function test_gitignore_covers_storage_guide_shots(): void
    {
        $gitignore = (string) file_get_contents(base_path('.gitignore'));
        $this->assertStringContainsString('storage/app/guide-shots/', $gitignore);
    }

    public function test_public_accountant_guide_is_a_menu_map_without_cookbook_amounts(): void
    {
        $path = base_path('docs/accountant-guide.md');
        $this->assertFileExists($path);
        $text = (string) file_get_contents($path);

        $this->assertStringContainsString('/admin/accountant-guide', $text);
        $this->assertStringContainsString('откройте панель', mb_strtolower($text, 'UTF-8'));
        $this->assertStringNotContainsString('### Шаги', $text);
        $this->assertStringNotContainsString('Записать выплату', $text);
    }

    public function test_payout_attribution_guide_is_still_the_live_queue(): void
    {
        $this->assertFileExists(app_path('Filament/Pages/PayoutAttributionGuide.php'));

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $accountant = User::factory()->create(['role' => Roles::ACCOUNTANT]);
        $this->actingAs($accountant);

        $this->assertTrue(PayoutAttributionGuide::canAccess());
        $this->get(PayoutAttributionGuide::getUrl())->assertOk()
            ->assertSee('Что сейчас ждёт вашего решения', false);
    }

    public function test_the_panel_page_rewrites_shots_to_the_storage_route(): void
    {
        $accountant = User::factory()->create(['role' => Roles::ACCOUNTANT]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($accountant);

        $html = (new AccountantGuide)->guideHtml();

        $this->assertIsString($html);
        $this->assertStringContainsString(AccountantGuide::SHOT_ROUTE_PREFIX, $html);
        $this->assertStringContainsString('provodka-1440.png', $html);
        $this->assertStringNotContainsString('src="screenshots/', $html);
        $this->assertStringNotContainsString('raw.githubusercontent.com', $html);
        $this->assertStringContainsString('Провести оплату студента вручную', $html);
    }

    public function test_guide_page_is_open_to_accountant_and_admin(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $accountant = User::factory()->create(['role' => Roles::ACCOUNTANT]);
        $this->actingAs($accountant);
        $this->assertTrue(AccountantGuide::canAccess());
        $this->get(AccountantGuide::getUrl())->assertOk();

        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $this->actingAs($admin);
        $this->assertTrue(AccountantGuide::canAccess());
        $this->get(AccountantGuide::getUrl())->assertOk();
    }

    public function test_guide_page_is_closed_to_teacher_and_manager(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $teacher = User::factory()->create(['role' => Roles::TEACHER]);
        $this->actingAs($teacher);
        $this->assertFalse(AccountantGuide::canAccess());
        $this->get(AccountantGuide::getUrl())->assertForbidden();

        $manager = User::factory()->create(['role' => Roles::MANAGER]);
        $this->actingAs($manager);
        $this->assertFalse(AccountantGuide::canAccess());
        $this->get(AccountantGuide::getUrl())->assertForbidden();
    }

    public function test_shot_route_is_gated_and_serves_from_storage(): void
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true
        );
        $this->assertNotFalse($png);
        Storage::disk('local')->put('guide-shots/accountant/provodka-1440.png', $png);

        $url = AccountantGuide::SHOT_ROUTE_PREFIX.'provodka-1440.png';

        $accountant = User::factory()->create(['role' => Roles::ACCOUNTANT]);
        $this->actingAs($accountant);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');

        $teacher = User::factory()->create(['role' => Roles::TEACHER]);
        $this->actingAs($teacher);
        $this->get($url)->assertForbidden();

        $this->get(AccountantGuide::SHOT_ROUTE_PREFIX.'../.env')->assertNotFound();
    }
}
