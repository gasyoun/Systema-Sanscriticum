<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\PublicTeacherHelpController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Публичный гид преподавателя /help/uchitelyu (рулинг MG 21-11-2026:
 * инструкции учебного процесса — на сайте, не на GitHub). Страница живёт на
 * одном источнике с кодом бота (docs/TEACHER_HELP_RU.md в том же деплое) —
 * гвард следит, что фразы останова на странице и в StopGroupIntentService
 * не разошлись.
 */
class TeacherHelpPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_exists_and_non_empty(): void
    {
        $path = base_path(PublicTeacherHelpController::SOURCE);

        $this->assertFileExists($path);
        $this->assertNotSame('', trim((string) file_get_contents($path)));
    }

    public function test_route_renders_stop_phrases(): void
    {
        $this->get('/help/uchitelyu')
            ->assertOk()
            ->assertSee('Гид преподавателя', false)
            ->assertSee('стоп', false)
            ->assertSee('серия окончена', false)
            ->assertSee('Каникулы', false)
            ->assertSee('Отмена занятия', false);
    }

    /** Страница и бот говорят ОДНИМ словарём: каждая кнопка бота названа на странице. */
    public function test_page_vocabulary_matches_bot_offers(): void
    {
        $source = (string) file_get_contents(base_path(PublicTeacherHelpController::SOURCE));
        $service = (string) file_get_contents(base_path('app/Services/Telegram/StopGroupIntentService.php'));

        foreach (['Серия окончена', 'Каникулы'] as $label) {
            $this->assertStringContainsString($label, $source, 'На странице нет кнопки бота: '.$label);
            $this->assertStringContainsString($label, $service, 'В боте нет кнопки, названной на странице: '.$label);
        }

        // Учебный словарь останова: фраза со страницы должна матчиться паттерном бота.
        $this->assertMatchesRegularExpression('/\bстоп\b/u', $source);
        $this->assertStringContainsString('серия окончена', $service);
    }
}
