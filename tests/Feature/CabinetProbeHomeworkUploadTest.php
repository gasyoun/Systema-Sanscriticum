<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * H37xx: синтетическая загрузка ДЗ внутри cabinet:probe — «постоянно
 * ломается подача ДЗ» повторялась трижды (молчаливый 64MB-порог, OOM сборки
 * PDF, дубли при зависании), а ни одна проверка не трогала реальный
 * upload-путь (все surfaces — GET). Эти тесты доказывают, что новая проверка
 * реально пишет/читает/удаляет через HomeworkService, а не только GET'ит.
 */
class CabinetProbeHomeworkUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cabinet_probe.ping_url', '');
        config()->set('cabinet_probe.telegram_chat_id', '');
        config()->set('cabinet_probe.telegram_soft_chat_id', '');
        config()->set('cabinet_probe.check_server_guards', false);
        config()->set('server_guards.verify_enabled', false);
        config()->set('services.telegram.bot_token', '');
        config()->set('features.cabinet_hybrid', false);
        config()->set('cabinet_probe.public_surfaces', []);
        config()->set('cabinet_probe.surfaces', [
            ['name' => 'student.dashboard', 'label' => 'manager /dvaram', 'severity' => 'critical'],
        ]);
        config()->set('cabinet_probe.student_surfaces', [
            ['name' => 'student.dashboard', 'label' => 'student /dvaram', 'severity' => 'critical'],
        ]);
        config()->set('cabinet_probe.hybrid_surfaces', []);
        config()->set('services.test_manager.password', '');
        Storage::fake('local');
    }

    private function seedStudent(string $password = 'stu-pass'): User
    {
        $user = User::factory()->create([
            'email' => 'stu@example.com',
            'password' => Hash::make($password),
            'role' => null,
        ]);

        config([
            'services.test_student.email' => 'stu@example.com',
            'services.test_student.password' => $password,
        ]);

        return $user;
    }

    private function seedSandboxLesson(array $overrides = []): Lesson
    {
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->free()->create(array_merge([
            'course_id' => $course->id,
            'homework_enabled' => true,
            'homework_closed_at' => null,
        ], $overrides));

        config([
            'cabinet_probe.homework_probe_course_slug' => $course->slug,
            'cabinet_probe.homework_probe_lesson_id' => $lesson->id,
        ]);

        return $lesson;
    }

    public function test_skipped_when_unconfigured(): void
    {
        $this->seedStudent();
        config([
            'cabinet_probe.homework_probe_course_slug' => '',
            'cabinet_probe.homework_probe_lesson_id' => 0,
        ]);

        $code = Artisan::call('cabinet:probe');
        $out = Artisan::output();

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Кабинет жив', $out);
        $this->assertSame(0, HomeworkSubmission::query()->count());
    }

    public function test_synthetic_upload_writes_and_cleans_up(): void
    {
        $this->seedStudent();
        $this->seedSandboxLesson();

        $code = Artisan::call('cabinet:probe');
        $out = Artisan::output();

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Кабинет жив', $out, $out);
        $this->assertStringContainsString('ДЗ-загрузка (synthetic)', $out);

        // Идемпотентно: ничего не осталось ни в БД, ни на диске.
        $this->assertSame(0, HomeworkSubmission::query()->count());
        $files = Storage::disk('local')->allFiles('homework');
        $this->assertSame([], $files, 'probe-файл должен быть удалён в finally: '.implode(',', $files));
    }

    public function test_closed_homework_lesson_is_soft_failure(): void
    {
        $this->seedStudent();
        $this->seedSandboxLesson(['homework_enabled' => false]);

        $code = Artisan::call('cabinet:probe');
        $out = Artisan::output();

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Кабинет болен', $out);
        $this->assertStringContainsString('homework-upload: probe-урок закрыт', $out);
        $this->assertSame(0, HomeworkSubmission::query()->count());
    }

    public function test_misconfigured_lesson_id_is_soft_failure(): void
    {
        $this->seedStudent();
        $course = Course::factory()->create();
        config([
            'cabinet_probe.homework_probe_course_slug' => $course->slug,
            'cabinet_probe.homework_probe_lesson_id' => 999999,
        ]);

        $code = Artisan::call('cabinet:probe');
        $out = Artisan::output();

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('homework-upload: probe-курс/урок не найден', $out);
    }

    public function test_check_can_be_disabled(): void
    {
        $this->seedStudent();
        $this->seedSandboxLesson();
        config(['cabinet_probe.check_homework_upload' => false]);

        $code = Artisan::call('cabinet:probe');
        $out = Artisan::output();

        $this->assertSame(0, $code, $out);
        $this->assertStringNotContainsString('homework-upload', $out);
        $this->assertStringNotContainsString('ДЗ-загрузка', $out);
        $this->assertSame(0, HomeworkSubmission::query()->count());
    }
}
