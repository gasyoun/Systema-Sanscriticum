<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LandingPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentStoryBlockTest extends TestCase
{
    use RefreshDatabase;

    // Уникальный slug на каждый тест: PromoController кэширует страницу на 24ч по
    // ключу promo_page_{slug}, поэтому переиспользование slug'а между тестами отдало
    // бы закэшированную страницу предыдущего теста (как и в CourseStreamsBlockTest).
    private function landingWithStory(string $slug, array $story, array $extra = []): LandingPage
    {
        return LandingPage::create([
            'title' => 'Лендинг с историями',
            'slug' => $slug,
            'is_active' => true,
            'content' => [[
                'type' => 'student_story_block',
                'data' => array_merge([
                    'title' => 'Истории учеников',
                    'stories' => [$story],
                ], $extra),
            ]],
        ]);
    }

    /** @test */
    public function block_renders_full_story_narrative(): void
    {
        $landing = $this->landingWithStory('story-full', [
            'date' => '2026',
            'track' => 'с нуля',
            'name' => 'Анна',
            'city' => 'Казань',
            'hook' => 'пришла читать мантры, боялась, что поздно в 45',
            'path' => 'Деванагари давалось тяжело, куратор разбил на 8 блоков.',
            'result' => 'За 8 блоков читает Бхагавадгиту в оригинале',
            'not_for' => 'тем, кто не готов уделять 3 часа в неделю',
        ]);

        $response = $this->get('/'.$landing->slug);

        $response->assertOk();
        $response->assertSee('Истории учеников');
        $response->assertSee('Анна');
        $response->assertSee('Казань');
        $response->assertSee('с нуля');
        $response->assertSee('пришла читать мантры, боялась, что поздно в 45');
        $response->assertSee('За 8 блоков читает Бхагавадгиту в оригинале');
        // Анти-кейс-приём («кому не зайдёт») — ключевое отличие от reviews_block.
        $response->assertSee('Кому не зайдёт:');
        $response->assertSee('тем, кто не готов уделять 3 часа в неделю');
    }

    /** @test */
    public function block_renders_without_optional_fields(): void
    {
        // Минимальная история (только имя + результат) не должна ронять страницу.
        $landing = $this->landingWithStory('story-minimal', [
            'name' => 'Иван',
            'result' => 'Сдал внутренний зачёт',
        ]);

        $response = $this->get('/'.$landing->slug);

        $response->assertOk();
        $response->assertSee('Иван');
        $response->assertSee('Сдал внутренний зачёт');
        // Анти-кейс-строка не выводится, если поле пустое.
        $response->assertDontSee('Кому не зайдёт:');
    }

    /** @test */
    public function empty_stories_list_does_not_error(): void
    {
        $landing = LandingPage::create([
            'title' => 'Пустой блок историй',
            'slug' => 'empty-stories',
            'is_active' => true,
            'content' => [[
                'type' => 'student_story_block',
                'data' => ['title' => 'Истории учеников', 'stories' => []],
            ]],
        ]);

        $this->get('/'.$landing->slug)->assertOk();
    }
}
