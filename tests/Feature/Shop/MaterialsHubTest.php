<?php

namespace Tests\Feature\Shop;

use App\Models\Article;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Tariff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * H387 — хаб «Материалы» (журнальный слой над магазином, паттерн Arzamas):
 * агрегатор статей блога + бесплатных бесед + preview-уроков в типизированные
 * карточки, воронка в каталог/курс, ссылки из навигации и «С чего начать»,
 * CTA-«лесенка» в конце статьи.
 */
class MaterialsHubTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // layouts.articles подключает бандл через @vite — в тестах манифест
        // не собирается, стандартный тестовый стаб Laravel.
        $this->withoutVite();
    }

    private function makeArticle(array $attrs = []): Article
    {
        return Article::create(array_merge([
            'slug' => 'materials-article-'.Str::lower(Str::random(8)),
            'title' => 'Как читать деванагари Qqq',
            'excerpt' => 'Короткий анонс статьи для карточки.',
            'body' => '<p>Тело статьи.</p>',
            'reading_time' => 7,
            'is_published' => true,
            'published_at' => now()->subDay(),
        ], $attrs));
    }

    private function makePreviewCourse(string $slug = 'materials-preview-course'): Course
    {
        $course = Course::factory()->create(['slug' => $slug]);
        Lesson::factory()->for($course)->create([
            'title' => 'Открытый пример урока Www',
            'is_preview' => true,
            'is_published' => true,
        ]);

        return $course;
    }

    /** @test */
    public function hub_renders_text_and_lesson_cards_from_real_models(): void
    {
        $article = $this->makeArticle();
        $course = $this->makePreviewCourse();

        $this->get('/online/materialy')
            ->assertOk()
            ->assertSee('Материалы')
            // Две разные типизированные карточки из реальных моделей.
            ->assertSee($article->title)
            ->assertSee('Текст')
            ->assertSee('Открытый пример урока Www')
            ->assertSee('Урок')
            // Воронка: карточка урока ведёт на preview и на страницу курса.
            ->assertSee(route('shop.course.preview', $course->slug), false)
            ->assertSee(route('shop.course.show', $course->slug), false)
            // Чтение-время статьи попало в чип.
            ->assertSee('7 мин чтения');
    }

    /** @test */
    public function hub_renders_video_cards_from_open_lessons(): void
    {
        $course = Course::factory()->create();
        Lesson::factory()->for($course)->create([
            'title' => 'Бесплатная беседа о сандхи Eee',
            'is_free' => true,
            'is_published' => true,
            'show_on_main' => true,
            'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ]);

        $this->get('/online/materialy')
            ->assertOk()
            ->assertSee('Бесплатная беседа о сандхи Eee')
            ->assertSee('Видео')
            // CTA видео-карточки — следующий шаг воронки «С чего начать».
            ->assertSee(route('shop.start'), false);
    }

    /** @test */
    public function open_lesson_without_recognizable_video_url_is_skipped(): void
    {
        $course = Course::factory()->create();
        Lesson::factory()->for($course)->create([
            'title' => 'Беседа без ссылки Rrr',
            'is_free' => true,
            'is_published' => true,
            'show_on_main' => true,
        ]);

        $this->get('/online/materialy')
            ->assertOk()
            ->assertDontSee('Беседа без ссылки Rrr');
    }

    /** @test */
    public function unpublished_and_hidden_content_is_not_listed(): void
    {
        $this->makeArticle([
            'title' => 'Черновик статьи Zzz',
            'is_published' => false,
            'published_at' => null,
        ]);

        // Preview-урок невидимого курса не попадает в хаб.
        $hidden = Course::factory()->hidden()->create();
        Lesson::factory()->for($hidden)->create([
            'title' => 'Скрытый preview Yyy',
            'is_preview' => true,
            'is_published' => true,
        ]);

        $this->get('/online/materialy')
            ->assertOk()
            ->assertDontSee('Черновик статьи Zzz')
            ->assertDontSee('Скрытый preview Yyy');
    }

    /** @test */
    public function hub_shows_filter_chips_and_funnel_ladder(): void
    {
        $this->makeArticle();
        $this->makePreviewCourse();

        $this->get('/online/materialy')
            ->assertOk()
            ->assertSee('data-analytics="materials-filter"', false)
            ->assertSee('data-analytics="materials-grid"', false)
            ->assertSee('data-analytics="product-ladder"', false);
    }

    /** @test */
    public function empty_hub_still_renders_with_catalog_cta(): void
    {
        $this->get('/online/materialy')
            ->assertOk()
            ->assertSee('Материалы')
            ->assertSee('Смотреть курсы');
    }

    /** @test */
    public function shop_nav_and_start_page_link_to_the_hub(): void
    {
        $this->get('/online')
            ->assertOk()
            ->assertSee('/online/materialy');

        $this->get('/online/s-chego-nachat')
            ->assertOk()
            ->assertSee('data-analytics="onramp-materials"', false)
            ->assertSee('Почитать и посмотреть бесплатно');
    }

    /** @test */
    public function article_page_shows_end_of_article_ladder_with_honest_prices(): void
    {
        $article = $this->makeArticle(['title' => 'Статья с лесенкой Ttt']);

        $live = Course::factory()->live()->create();
        Tariff::factory()->for($live)->create(['type' => 'block', 'price' => 4500, 'is_active' => true]);

        $this->get('/s/'.$article->slug)
            ->assertOk()
            ->assertSee('data-analytics="article-funnel"', false)
            ->assertSee('data-analytics="product-ladder"', false)
            // Якорь цены честно пришёл из тарифов, не захардкожен.
            ->assertSee('от 4 500 ₽/блок')
            // Обратная ссылка в хаб.
            ->assertSee(route('shop.materials'), false);
    }
}
