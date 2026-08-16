<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Http\Controllers\LlmsTxtController;
use App\Models\Article;
use App\Models\Course;
use App\Models\Dictionary;
use App\Models\DictionaryWord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LlmsTxtTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(LlmsTxtController::CACHE_KEY);
    }

    public function test_llms_txt_is_plain_text_and_lists_visible_course(): void
    {
        Course::factory()->create([
            'title' => 'Видимый курс санскрита',
            'slug' => 'visible-sanskrit-course',
            'format' => 'recorded',
            'is_visible' => true,
        ]);
        Course::factory()->hidden()->create([
            'title' => 'Скрытый курс',
            'slug' => 'hidden-sanskrit-course',
            'format' => 'live',
        ]);

        $res = $this->get('/llms.txt')->assertOk();
        $res->assertHeader('Content-Type', 'text/plain; charset=utf-8');

        $body = $res->getContent();
        $this->assertStringContainsString('Общество ревнителей санскрита', $body);
        $this->assertStringContainsString('/online', $body);
        $this->assertStringContainsString('/slovar', $body);
        $this->assertStringContainsString('/k/visible-sanskrit-course', $body);
        $this->assertStringContainsString('Видимый курс санскрита', $body);
        $this->assertStringContainsString("\trecorded", $body);
        $this->assertStringNotContainsString('/k/hidden-sanskrit-course', $body);
        $this->assertStringNotContainsString('Скрытый курс', $body);
        $this->assertStringNotContainsString('/cabinet', $body);
        $this->assertStringNotContainsString('/dvaram', $body);
    }

    public function test_llms_txt_lists_published_articles_not_drafts(): void
    {
        Article::query()->create([
            'slug' => 'zachem-sanskrit',
            'title' => 'Зачем санскрит',
            'body' => '<p>x</p>',
            'is_published' => true,
            'published_at' => now()->subDay(),
        ]);
        Article::query()->create([
            'slug' => 'draft-essay',
            'title' => 'Черновик',
            'body' => '<p>y</p>',
            'is_published' => false,
            'published_at' => null,
        ]);

        $body = $this->get('/llms.txt')->assertOk()->getContent();

        $this->assertStringContainsString('/s/zachem-sanskrit', $body);
        $this->assertStringNotContainsString('/s/draft-essay', $body);
    }

    public function test_llms_txt_omits_dictionary_word_urls(): void
    {
        $dict = Dictionary::create([
            'name' => 'Словарь Кочергиной',
            'is_active' => true,
        ]);
        DictionaryWord::create([
            'dictionary_id' => $dict->id,
            'devanagari' => 'सत्',
            'iast' => 'sat',
            'cyrillic' => 'сат',
            'translation' => 'истина',
            'slug' => 'sat',
        ]);

        $body = $this->get('/llms.txt')->assertOk()->getContent();

        $this->assertStringContainsString('/slovar', $body);
        $this->assertStringNotContainsString('/slovar/sat', $body);
    }

    public function test_course_change_flushes_llms_cache(): void
    {
        $course = Course::factory()->create([
            'title' => 'Старое имя',
            'slug' => 'cache-flush-course',
            'is_visible' => true,
        ]);

        $first = $this->get('/llms.txt')->assertOk()->getContent();
        $this->assertStringContainsString('Старое имя', $first);

        $course->update(['title' => 'Новое имя курса']);

        $second = $this->get('/llms.txt')->assertOk()->getContent();
        $this->assertStringContainsString('Новое имя курса', $second);
        $this->assertStringNotContainsString('Старое имя', $second);
    }
}
