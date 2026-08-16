<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\HomeworkFile;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\Teacher;
use App\Models\User;
use App\Services\HomeworkImagePdfService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Даунскейл страниц ревью-PDF домашки.
 *
 * Инцидент 16-08-2026: jpeg/png вставлялись в PDF байт-в-байт, четыре
 * телефонных фото по ~5 МБ съедали 128 МБ FPM, студент получал 500 при уже
 * сохранённой сдаче и ретраил, плодя дубли. Теперь каждая страница ужимается
 * до pdf_max_edge_px/JPEG, а неподъёмная картинка даёт заглушку, а не 500.
 */
class HomeworkImagePdfDownscaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Storage::fake('local');
    }

    private function service(): HomeworkImagePdfService
    {
        return app(HomeworkImagePdfService::class);
    }

    /** Настоящий JPEG заданных габаритов (двухцветный — сжимается быстро). */
    private function jpegBytes(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, intdiv($w, 2) - 1, $h - 1, imagecolorallocate($im, 200, 40, 40));
        imagefilledrectangle($im, intdiv($w, 2), 0, $w - 1, $h - 1, imagecolorallocate($im, 40, 60, 200));

        ob_start();
        imagejpeg($im, null, 90);
        imagedestroy($im);

        return (string) ob_get_clean();
    }

    /** @return array{0: int, 1: int} габариты jpeg-результата */
    private function dimensionsOf(string $jpeg): array
    {
        $im = imagecreatefromstring($jpeg);
        $this->assertNotFalse($im, 'результат обязан быть валидной картинкой');
        $dims = [imagesx($im), imagesy($im)];
        imagedestroy($im);

        return $dims;
    }

    /** @test */
    public function large_image_is_downscaled_below_max_edge(): void
    {
        $source = $this->jpegBytes(3000, 4000);

        $result = $this->service()->convertToPdfJpeg($source, 'image/jpeg');

        $this->assertNotNull($result);
        [$w, $h] = $this->dimensionsOf($result);
        $this->assertLessThanOrEqual((int) config('homework.pdf_max_edge_px'), max($w, $h));
        $this->assertLessThan(strlen($source), strlen($result), 'даунскейл обязан уменьшать размер');
        // Пропорции сохранены (3:4 с точностью до округления).
        $this->assertEqualsWithDelta(3 / 4, $w / $h, 0.01);
    }

    /** @test */
    public function plain_jpeg_is_reencoded_not_passed_through(): void
    {
        // Прежний код отдавал jpeg байт-в-байт — ровно это и был OOM-путь.
        $source = $this->jpegBytes(2000, 1500);

        $result = $this->service()->convertToPdfJpeg($source, 'image/jpeg');

        $this->assertNotNull($result);
        $this->assertNotSame($source, $result, 'jpeg не имеет права проходить конвейер нетронутым');
        [$w, $h] = $this->dimensionsOf($result);
        $this->assertLessThanOrEqual((int) config('homework.pdf_max_edge_px'), max($w, $h));
    }

    /** @test */
    public function small_image_keeps_its_dimensions(): void
    {
        $source = $this->jpegBytes(640, 480);

        $result = $this->service()->convertToPdfJpeg($source, 'image/jpeg');

        $this->assertNotNull($result);
        $this->assertSame([640, 480], $this->dimensionsOf($result), 'мелкую картинку не растим и не ужимаем');
    }

    /** @test */
    public function transparent_png_gets_white_backdrop(): void
    {
        $im = imagecreatetruecolor(200, 200);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefilledrectangle($im, 0, 0, 199, 199, imagecolorallocatealpha($im, 0, 0, 0, 127));
        ob_start();
        imagepng($im);
        imagedestroy($im);
        $source = (string) ob_get_clean();

        $result = $this->service()->convertToPdfJpeg($source, 'image/png');

        $this->assertNotNull($result);
        $out = imagecreatefromstring($result);
        $rgb = imagecolorsforindex($out, imagecolorat($out, 10, 10));
        imagedestroy($out);

        // Прозрачность обязана лечь на белый, а не на чёрный.
        $this->assertGreaterThanOrEqual(250, $rgb['red']);
        $this->assertGreaterThanOrEqual(250, $rgb['green']);
        $this->assertGreaterThanOrEqual(250, $rgb['blue']);
    }

    /**
     * Картинка, чей декод заведомо не влезает в память, превращается в
     * страницу-заглушку — сборка выживает, остальные страницы на месте.
     * Заголовок PNG 60000×60000 без пиксельных данных: guard срезает ДО
     * декодирования, поэтому тесту не нужны гигабайты.
     *
     * @test
     */
    public function oversized_image_becomes_placeholder_and_rebuild_survives(): void
    {
        Log::spy();

        $submission = $this->submissionWithOneSmallImage();
        $comment = $submission->comments()->first();

        $huge = "\x89PNG\r\n\x1a\n"
            .pack('N', 13).'IHDR'
            .pack('N', 60000).pack('N', 60000)
            ."\x08\x02\x00\x00\x00"
            .pack('N', 0);
        Storage::disk('local')->put('homework-test/huge.png', $huge);

        HomeworkFile::create([
            'comment_id' => $comment->id,
            'disk' => 'local',
            'path' => 'homework-test/huge.png',
            'original_name' => 'huge.png',
            'size' => strlen($huge),
            'mime' => 'image/png',
        ]);

        $path = $this->service()->rebuild(
            $submission->fresh(['comments.files', 'user', 'lesson', 'course']),
        );

        $this->assertNotNull($path, 'сборка обязана выжить при неподъёмной странице');
        $this->assertStringStartsWith('%PDF', (string) Storage::disk('local')->get($path));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'image too large'))
            ->once();
    }

    /**
     * Сквозной кейс инцидента: сдача крупных фото проходит без 500 и собирает PDF.
     *
     * @test
     */
    public function submitting_large_photos_succeeds_and_builds_pdf(): void
    {
        $teacher = Teacher::create(['name' => 'Препод', 'email' => 'teacher-oom@example.test']);
        User::factory()->create(['role' => Roles::TEACHER, 'teacher_id' => $teacher->id]);
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);
        $lesson = Lesson::factory()->for($course)->create([
            'homework_enabled' => true,
            'homework_prompt' => 'Сфотографируйте тетрадь',
            'is_free' => true,
            'block_number' => 2,
        ]);
        $student = User::factory()->create();

        $response = $this->actingAs($student)->post(
            route('student.homework.store', [$course->slug, $lesson->id]),
            [
                'action' => 'submit',
                'body' => 'Фото тетради',
                'files' => [
                    UploadedFile::fake()->image('big1.jpg', 2600, 2000),
                    UploadedFile::fake()->image('big2.jpg', 2000, 2600),
                ],
            ],
        );

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $submission = HomeworkSubmission::where('user_id', $student->id)
            ->where('lesson_id', $lesson->id)->firstOrFail();

        $pdf = $this->service();
        $this->assertTrue($pdf->exists($submission));
        $this->assertStringStartsWith('%PDF', (string) Storage::disk('local')->get($pdf->pathFor($submission)));
    }

    private function submissionWithOneSmallImage(): HomeworkSubmission
    {
        $teacher = Teacher::create(['name' => 'Препод', 'email' => 'teacher-ds@example.test']);
        User::factory()->create(['role' => Roles::TEACHER, 'teacher_id' => $teacher->id]);
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);
        $lesson = Lesson::factory()->for($course)->create([
            'homework_enabled' => true,
            'homework_prompt' => 'Задание',
            'is_free' => true,
            'block_number' => 2,
        ]);
        $student = User::factory()->create();

        $this->actingAs($student)->post(
            route('student.homework.store', [$course->slug, $lesson->id]),
            [
                'action' => 'submit',
                'body' => 'Ответ',
                'files' => [UploadedFile::fake()->image('small.jpg', 300, 200)],
            ],
        )->assertRedirect();

        return HomeworkSubmission::where('user_id', $student->id)
            ->where('lesson_id', $lesson->id)->firstOrFail();
    }
}
