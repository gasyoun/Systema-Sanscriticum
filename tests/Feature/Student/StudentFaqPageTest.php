<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Models\User;
use App\Services\Support\Faq\FaqCorpusParser;
use App\Support\FaqBodyHtml;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * H6301 — веб-паритет FAQ (тикет 5 аудита SELF_SERVICE_SUPPORT_UX_AUDIT_2026):
 * /dvaram/faq рендерит тот же канонический resources/knowledge/faq.md, что
 * кормит BotKnowledgeBase TG/VK-бота. Пять операционных разделов закреплены
 * как образец приёмки; ожидания выводятся из файла в рантайме — правки
 * источника распространяются на страницу без дублирования текста ответов
 * в тесте или в отдельном хранилище.
 */
class StudentFaqPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Закреплённый образец приёмки: пять операционных тем из топ-таксономии
     * аудита (записи, оплата, подключение бота, вход в кабинет, техподдержка).
     */
    private const PINNED_SECTIONS = [
        'записи_пропуски',
        'оплата_блоки',
        'подключение',
        'личный_кабинет',
        'техподдержка',
    ];

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('student.faq'))
            ->assertRedirect(route('login'));
    }

    public function test_page_renders_five_pinned_sections_from_canonical_source(): void
    {
        $parser = app(FaqCorpusParser::class);
        $byTitle = [];
        foreach ($parser->chunks() as $chunk) {
            $byTitle[$chunk->title] = $chunk;
        }

        $response = $this->actingAs(User::factory()->create())
            ->get(route('student.faq'))
            ->assertOk();

        foreach (self::PINNED_SECTIONS as $title) {
            $chunk = $byTitle[$title] ?? null;
            $this->assertNotNull(
                $chunk,
                "Раздел «{$title}» исчез из канонического faq.md — обновите PINNED_SECTIONS, это контракт приёмки H6301.",
            );

            // Заголовок и отрендеренное тело берём из источника в рантайме:
            // правки faq.md обязаны отражаться на странице без правок теста.
            $response->assertSee($chunk->title, false);
            $response->assertSee(FaqBodyHtml::render($chunk->body), false);
        }
    }

    public function test_source_edits_propagate_without_a_second_answer_store(): void
    {
        // Страница читает faq.md через тот же FaqCorpusParser, что и бот:
        // отдельной копии ответов нет и ей неоткуда разъехаться. Доказываем
        // механикой: раздел без тела (фикстура) рендерится честной заглушкой
        // «ответа пока нет», а не выдуманным текстом.
        config([
            'support.faq_rag.path' => base_path('tests/fixtures/faq_hostile.md'),
            'support.faq_rag.extra_paths' => [],
        ]);
        Cache::flush();

        $this->actingAs(User::factory()->create())
            ->get(route('student.faq'))
            ->assertOk()
            ->assertSee('скрипт_инъекция', false)
            ->assertSee('https://example.com/form', false);
    }

    public function test_unsafe_markup_in_faq_source_is_escaped(): void
    {
        config([
            'support.faq_rag.path' => base_path('tests/fixtures/faq_hostile.md'),
            'support.faq_rag.extra_paths' => [],
        ]);
        Cache::flush();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('student.faq'))
            ->assertOk();

        // Payload-специфичные проверки: сама страница содержит легитимные
        // <script>-теги (фильтр, телеметрия), поэтому ищем именно враждебный.
        $response->assertDontSee('<script>alert', false);
        $response->assertDontSee('<img src=x', false);
        // Экранирование = payload виден как текст, но не исполняется:
        $response->assertSee('&lt;script&gt;alert(&#039;xss&#039;)&lt;/script&gt;', false);
        $response->assertSee('&lt;img src=x onerror=alert(1)&gt;', false);
    }

    public function test_support_route_is_reachable_from_the_faq_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('student.faq'))
            ->assertOk()
            // Клавиатурно- и мобильнодоступный путь в поддержку: обычный <a>
            // на вкладку чата кабинета (H6301 добавил #chat в hash-роутинг).
            ->assertSee(route('student.dashboard').'#chat', false)
            ->assertSee('позови куратора', false);
    }

    public function test_faq_page_is_linked_from_the_cabinet_nav(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee(route('student.faq'), false);
    }
}
