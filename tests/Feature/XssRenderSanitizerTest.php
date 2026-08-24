<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\LandingPage;
use App\Models\Lesson;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H3309: staff rich-text `{!! !!}` синки не должны пропускать stored XSS.
 * Payload кладётся в источник (модель/сессия), рендерится реальный экран,
 * проверяем инертность полезной нагрузки и выживание легитимной разметки.
 */
class XssRenderSanitizerTest extends TestCase
{
    use RefreshDatabase;

    /** RichEditor-подобный payload: легитимная разметка + три класса атаки. */
    private const EVIL_HTML =
        '<p><strong>Легитимно</strong></p>'
        .'<img src=x onerror=alert(1)>'
        .'<script>alert(2)</script>'
        .'<a href="javascript:alert(3)">тык</a>';

    public function test_announcement_content_is_sanitized_on_student_messages_page(): void
    {
        Announcement::create([
            'title' => 'XSS probe',
            'preview' => 'probe',
            'content' => self::EVIL_HTML,
            'is_published' => true,
        ]);
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get(route('student.messages'))->assertOk()->getContent();

        $this->assertInert($html);
    }

    public function test_teacher_bio_is_sanitized_on_course_page(): void
    {
        $teacher = Teacher::factory()->create(['bio' => self::EVIL_HTML]);
        $course = Course::factory()->create(['slug' => 'xss-teacher-probe']);
        $course->teacher()->associate($teacher);
        $course->save();

        $html = $this->get('/k/xss-teacher-probe')->assertOk()->getContent();

        $this->assertInert($html);
    }

    public function test_landing_legacy_description_is_sanitized(): void
    {
        $page = new LandingPage(['title' => 'XSS probe', 'slug' => 'xss-legacy-probe', 'is_active' => true]);
        $page->description = self::EVIL_HTML;
        $page->save();

        $html = $this->get('/xss-legacy-probe')->assertOk()->getContent();

        $this->assertInert($html);
    }

    public function test_landing_builder_faq_answer_is_sanitized(): void
    {
        $page = new LandingPage(['title' => 'XSS probe FAQ', 'slug' => 'xss-faq-probe', 'is_active' => true]);
        $page->content = [
            [
                'type' => 'faq_block',
                'data' => [
                    'items' => [['question' => 'Вопрос?', 'answer' => self::EVIL_HTML]],
                ],
            ],
        ];
        $page->save();

        $html = $this->get('/xss-faq-probe')->assertOk()->getContent();

        $this->assertInert($html);
    }

    public function test_landing_builder_instructor_href_and_bio_are_neutralized(): void
    {
        $page = new LandingPage(['title' => 'XSS probe instructor', 'slug' => 'xss-instructor-probe', 'is_active' => true]);
        $page->content = [
            [
                'type' => 'instructor_block',
                'data' => [
                    'name' => 'Инструктор',
                    'role' => 'Инструктор санскрита',
                    'bio' => self::EVIL_HTML,
                    'publications' => [
                        ['title' => 'Книга', 'url' => 'javascript:alert(4)" onmouseover="alert(5)'],
                    ],
                ],
            ],
        ];
        $page->save();

        $html = $this->get('/xss-instructor-probe')->assertOk()->getContent();

        $this->assertInert($html);
        $this->assertStringNotContainsString('onmouseover', $html);
    }

    public function test_announcement_email_renders_content_sanitized(): void
    {
        $announcement = Announcement::create([
            'title' => 'XSS probe',
            'preview' => 'probe',
            'content' => self::EVIL_HTML,
            'is_published' => true,
        ]);

        $html = view('emails.announcement', [
            'announcement' => $announcement,
            'user' => User::factory()->make(),
        ])->render();

        $this->assertInert($html);
    }

    public function test_lead_adhoc_email_renders_body_sanitized(): void
    {
        $html = view('emails.lead.ad-hoc', [
            'bodyText' => self::EVIL_HTML,
            'leadName' => 'Тест',
            'subjectLine' => 'Тема',
        ])->render();

        $this->assertInert($html);
    }

    public function test_certificate_course_title_is_escaped_but_pipe_break_survives(): void
    {
        $certificate = new Certificate();
        $certificate->document_type = 'certificate';
        $certificate->template = null;

        $html = view('certificates.default', [
            'certificate' => $certificate,
            'course' => new Course(),
            'student_name' => 'Тест Тестов',
            'course_title' => 'Санскрит<script>alert(6)</script>|для начинающих',
            'number' => '000042',
            'date' => '24.08.2026',
            'qr_image' => null,
            'bg_base64' => '',
        ])->render();

        $this->assertStringNotContainsString('<script>alert(6)', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        // Pipe已被str_replace替换为<br>，所以检查替换后的两个片段
        $this->assertStringContainsString('&lt;/script&gt;<br>для', $html);
    }

    public function test_thankyou_pixel_ids_cast_to_int_before_js_contexts(): void
    {
        $html = $this->withSession([
            'yandex_id' => "1); alert('boom-yandex'); (",
            'vk_id' => "9); alert('boom-vk'); (",
        ])->get('/thank-you')->assertOk()->getContent();

        $this->assertStringNotContainsString('boom-yandex', $html);
        $this->assertStringNotContainsString('boom-vk', $html);
        $this->assertStringContainsString('ym(1,', $html);
    }

    public function test_student_video_buttons_use_js_safe_escaping(): void
    {
        $lesson = Lesson::factory()->make([
            'video_url' => "https://youtu.be/x');alert(7);('",
            'rutube_url' => "https://rutube.ru/y');alert(8);('",
            'flash_cards' => [['front' => "Ф');alert(9);('", 'back' => 'Б']],
        ]);

        $html = view('student', ['lesson' => $lesson, 'lessons' => collect([$lesson])])->render();

        // @js() escapes ' to \u0027 — raw payload string cannot break out
        $this->assertStringNotContainsString("');alert(", $html);
        $this->assertStringContainsString('\u0027', $html);
    }

    /**
     * Инертность payload + выживание легитимной разметки.
     *
     * Проверяем ТОЛЬКО опасные векторы (sanitizer должен был их удалить),
     * а не содержимое payload-строки — текст alert(2) безопасен внутри
     * JSON-строки в onclick и т.п. и сам по себе не означает XSS.
     */
    private function assertInert(string $html): void
    {
        $this->assertStringNotContainsString('onerror=', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('javascript:alert', $html);
        $this->assertStringContainsString('<p><strong>Легитимно</strong></p>', $html);
    }
}
