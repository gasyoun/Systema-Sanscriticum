<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H4294 — клиентский префлайт размера в форме сдачи ДЗ.
 *
 * 07-09-2026 студент гр.61 четыре раза пытался отправить ~368 МБ видео:
 * nginx убил тело голой 413 (server: samskrte.ru, error.log .92), телефон
 * слил трафик впустую, ни серверная валидация, ни PostTooLargeException-
 * редирект до формы не доехали. Форма обязана нести те же пороги, что и
 * серверная валидация, и резать тяжёлые файлы ДО старта загрузки.
 */
class HomeworkClientSizeGateTest extends TestCase
{
    use RefreshDatabase;

    private function renderHomeworkForm(): string
    {
        $teacher = Teacher::create(['name' => 'Препод', 'email' => 'teacher@example.test']);
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);
        $lesson = Lesson::factory()->for($course)->create([
            'homework_enabled' => true,
            'homework_prompt' => 'Сделайте упражнение',
            'is_free' => true,
            'block_number' => 2,
        ]);
        $student = User::factory()->create();

        return $this->actingAs($student)
            ->get(route('student.lesson', [$course->slug, $lesson->id]))
            ->assertOk()
            ->getContent();
    }

    /** @test */
    public function form_carries_client_side_size_limits_from_config(): void
    {
        config([
            'homework.max_file_kb' => 30720,
            'homework.total_max_kb' => 92160,
        ]);

        $html = $this->renderHomeworkForm();

        // Пороги уходят в Alpine байтами — ровно из config/homework.php
        // (единый источник H1343), без дублей числа в разметке.
        $this->assertStringContainsString('maxFileBytes: 30720 * 1024', $html);
        $this->assertStringContainsString('totalMaxBytes: 92160 * 1024', $html);
        $this->assertStringContainsString('oversizeHint', $html);
    }

    /** @test */
    public function form_limits_follow_config_changes(): void
    {
        config([
            'homework.max_file_kb' => 1024,
            'homework.total_max_kb' => 2048,
        ]);

        $html = $this->renderHomeworkForm();

        $this->assertStringContainsString('maxFileBytes: 1024 * 1024', $html);
        $this->assertStringContainsString('totalMaxBytes: 2048 * 1024', $html);
    }
}
