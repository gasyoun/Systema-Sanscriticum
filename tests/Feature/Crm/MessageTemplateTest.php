<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\Course;
use App\Models\MessageTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Общая библиотека шаблонов (H221): подстановка плейсхолдеров и выборка активных
 * шаблонов по категории.
 */
class MessageTemplateTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function render_substitutes_placeholders(): void
    {
        $user = User::factory()->create(['name' => 'Мару']);
        $course = Course::factory()->create(['title' => 'Санскрит A1']);

        $tpl = MessageTemplate::factory()->create([
            'body' => 'Намасте, {name}! Курс «{course}», блок №{block}. Оплата: {pay_link}',
        ]);

        $rendered = $tpl->render($user, $course, 3);

        $this->assertStringContainsString('Намасте, Мару!', $rendered);
        $this->assertStringContainsString('«Санскрит A1»', $rendered);
        $this->assertStringContainsString('блок №3', $rendered);
        $this->assertStringNotContainsString('{', $rendered, 'плейсхолдеры не должны остаться');
    }

    /** @test */
    public function render_without_course_uses_login_link_and_empty_course(): void
    {
        $user = User::factory()->create(['name' => 'Гость']);
        $tpl = MessageTemplate::factory()->create([
            'body' => '{name}: {course}|{block}|{pay_link}',
        ]);

        $rendered = $tpl->render($user);

        $this->assertStringContainsString('Гость: |', $rendered);
        $this->assertStringContainsString('/login', $rendered);
    }

    /** @test */
    public function for_category_scope_returns_only_active_of_category(): void
    {
        MessageTemplate::factory()->category(MessageTemplate::CATEGORY_REACTIVATION)->create();
        MessageTemplate::factory()->category(MessageTemplate::CATEGORY_REACTIVATION)->inactive()->create();
        MessageTemplate::factory()->category(MessageTemplate::CATEGORY_LEAD)->create();

        $rows = MessageTemplate::query()
            ->forCategory(MessageTemplate::CATEGORY_REACTIVATION)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertTrue($rows->first()->is_active);
        $this->assertSame(MessageTemplate::CATEGORY_REACTIVATION, $rows->first()->category);
    }
}
