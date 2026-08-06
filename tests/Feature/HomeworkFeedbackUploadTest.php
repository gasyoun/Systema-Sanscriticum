<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\HomeworkSubmissionResource\Pages\ViewHomeworkSubmission;
use App\Models\Course;
use App\Models\HomeworkComment;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Файлы с правками у проверяющего ДЗ: поле обещало 30 МБ, а принимало 12.
 *
 * Причина — вендорный дефолт Livewire `max:12288`: он валидирует временную
 * загрузку РАНЬШЕ, чем Filament проверит свой maxSize(), поэтому всё тяжелее
 * 12 МБ отваливалось с невнятным «The file failed to upload». Лечится
 * config/livewire.php; тесты ниже держат и потолок, и то, что поле перестало
 * хардкодить пороги мимо config/homework.php.
 */
class HomeworkFeedbackUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
    }

    private function submission(): HomeworkSubmission
    {
        $teacher = Teacher::create(['name' => 'Препод', 'email' => 'teacher-upload@example.test']);
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);
        $lesson = Lesson::factory()->for($course)->create();

        return HomeworkSubmission::create([
            'user_id' => User::factory()->create()->id,
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'status' => HomeworkSubmission::STATUS_SUBMITTED,
            'last_activity_at' => now(),
        ]);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }

    /**
     * Главный фиксатор: удалят config/livewire.php — вернётся тихий потолок в
     * 12 МБ, и все maxSize выше него снова станут обещанием без покрытия.
     * Резолвер проверяем отдельно от конфига: mergeConfigFrom мержит только
     * верхний уровень, так что «ключ есть» и «Livewire его увидел» — разное.
     */
    public function test_livewire_upload_ceiling_is_raised_above_the_vendor_default(): void
    {
        $this->assertContains('max:102400', config('livewire.temporary_file_upload.rules'));
        $this->assertContains('max:102400', FileUploadConfiguration::rules());
        $this->assertNotContains('max:12288', FileUploadConfiguration::rules(), 'Действует вендорный дефолт 12 МБ: config/livewire.php не подхватился.');
    }

    /** Потолок Livewire обязан быть не ниже самого щедрого поля проекта. */
    public function test_ceiling_covers_the_largest_file_upload_field(): void
    {
        $rules = FileUploadConfiguration::rules();
        $max = 0;
        foreach ($rules as $rule) {
            if (is_string($rule) && str_starts_with($rule, 'max:')) {
                $max = (int) substr($rule, 4);
            }
        }

        // Вложения урока (LessonResource) — 100 МБ, максимум по проекту.
        $this->assertGreaterThanOrEqual(102400, $max);
        // И заодно не ниже порогов ДЗ, ради которых всё затевалось: потолок
        // ниже порога поля означал бы, что поле снова обещает больше, чем берёт.
        $this->assertGreaterThanOrEqual((int) config('homework.max_file_kb'), $max);
        $this->assertGreaterThanOrEqual((int) config('homework.feedback_max_file_kb'), $max);
    }

    /**
     * Порог проверяющего свой и выше студенческого: он кладёт разбор ПОВЕРХ
     * работы. Числа берутся из конфига, а не из литералов в коде.
     */
    public function test_feedback_field_reads_its_limits_from_config(): void
    {
        config([
            'homework.feedback_max_file_kb' => 40960,
            'homework.max_file_kb' => 12345,
            'homework.max_files' => 3,
        ]);

        $this->assertSame(40960, $this->feedbackField()->getMaxSize());
        $this->assertSame(3, $this->feedbackField()->getMaxFiles());
    }

    /** Погашенный ключ откатывает поле к студенческому порогу, а не к нулю. */
    public function test_feedback_field_falls_back_to_the_student_threshold(): void
    {
        config(['homework.feedback_max_file_kb' => 0, 'homework.max_file_kb' => 12345]);

        $this->assertSame(12345, $this->feedbackField()->getMaxSize());
    }

    /** Дефолт из репозитория: проверяющему 40 МБ, студенту 30 МБ. */
    public function test_reviewer_threshold_is_higher_than_the_student_one_by_default(): void
    {
        $this->assertSame(40960, (int) config('homework.feedback_max_file_kb'));
        $this->assertSame(30720, (int) config('homework.max_file_kb'));
        $this->assertGreaterThan(
            (int) config('homework.max_file_kb'),
            (int) config('homework.feedback_max_file_kb'),
        );
    }

    private function feedbackField(): FileUpload
    {
        $this->actingAsAdmin();
        $submission = $this->submission();

        return Livewire::test(ViewHomeworkSubmission::class, ['record' => $submission->id])
            ->mountAction('accept')
            ->instance()
            ->getMountedActionForm()
            ->getComponent(fn ($component): bool => $component->getName() === 'feedback_files');
    }

    /** Happy-path: приложенный проверяющим файл доезжает до комментария-вердикта. */
    public function test_review_stores_the_reviewer_files(): void
    {
        Storage::fake('local');

        $this->actingAsAdmin();
        $submission = $this->submission();

        $path = 'homework/feedback/'.$submission->id.'/pravki.txt';
        Storage::disk('local')->put($path, 'правки по работе');

        Livewire::test(ViewHomeworkSubmission::class, ['record' => $submission->id])
            ->mountAction('accept')
            ->setActionData(['body' => 'Смотри файл', 'feedback_files' => [$path]])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $comment = HomeworkComment::where('submission_id', $submission->id)
            ->where('type', HomeworkComment::TYPE_REVIEW)
            ->firstOrFail();

        $this->assertDatabaseHas('homework_files', [
            'comment_id' => $comment->id,
            'disk' => 'local',
            'path' => $path,
            'original_name' => 'pravki.txt',
        ]);
    }
}
