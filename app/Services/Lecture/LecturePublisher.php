<?php

declare(strict_types=1);

namespace App\Services\Lecture;

use App\Models\Lesson;
use App\Models\LectureDraft;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Публикация черновика: копирует output/lecture.html и slides/* из storage/app/lectures/{id}/
 * в public/lectures/{slug}/, привязывает к Lesson и переключает статус draft → published.
 */
class LecturePublisher
{
    public function __construct(
        private readonly LectureStorage $storage,
    ) {}

    /**
     * @return array{public_html: string, public_slides_dir: string, lesson_id: int}
     */
    public function publish(LectureDraft $draft, ?int $lessonId = null): array
    {
        if ($draft->status !== LectureDraft::STATUS_BUILT) {
            throw new RuntimeException("Публиковать можно только черновик в статусе 'built' (сейчас: {$draft->status})");
        }

        $workingDir = $this->storage->absoluteWorkingDir($draft);
        $sourceHtml = $workingDir . DIRECTORY_SEPARATOR . 'output' . DIRECTORY_SEPARATOR . 'lecture.html';
        if (!is_file($sourceHtml)) {
            throw new RuntimeException("Не найден собранный HTML: {$sourceHtml}");
        }

        $publicRoot = public_path('lectures' . DIRECTORY_SEPARATOR . $draft->slug);
        $publicSlidesDir = $publicRoot . DIRECTORY_SEPARATOR . 'slides';
        $publicHtml = $publicRoot . DIRECTORY_SEPARATOR . 'index.html';

        // Чистим старую публикацию (на случай повторной)
        if (is_dir($publicRoot)) {
            File::deleteDirectory($publicRoot);
        }
        File::ensureDirectoryExists($publicRoot);
        if (!is_dir($publicRoot)) {
            throw new RuntimeException("Не удалось создать {$publicRoot}");
        }

        // HTML с переписанными путями (на абсолютные относительно публичного корня)
        $html = File::get($sourceHtml);
        $publicSlidesUrl = '/lectures/' . $draft->slug . '/slides';
        $html = str_replace('src="./src/img/', 'src="' . $publicSlidesUrl . '/', $html);
        $html = str_replace("src='./src/img/", "src='" . $publicSlidesUrl . '/', $html);
        $html = preg_replace('#href="\./src/style\.css(\?[^"]*)?"#', 'href="/lecture-styles/style.css"', $html);
        File::put($publicHtml, $html);

        // Слайды (если есть)
        $sourceSlides = $workingDir . DIRECTORY_SEPARATOR . 'slides';
        if (is_dir($sourceSlides)) {
            File::ensureDirectoryExists($publicSlidesDir);
            File::copyDirectory($sourceSlides, $publicSlidesDir);
        }

        return DB::transaction(function () use ($draft, $lessonId, $publicHtml, $publicSlidesDir) {
            $lesson = $this->resolveLesson($draft, $lessonId);
            $publicUrl = '/lectures/' . $draft->slug . '/index.html';

            // Кладём ссылку на опубликованную страницу в transcript_file Lesson
            $lesson->forceFill(['transcript_file' => $publicUrl])->save();

            $draft->forceFill([
                'status'           => LectureDraft::STATUS_PUBLISHED,
                'lesson_id'        => $lesson->id,
                'output_html_path' => $this->storage->relativePath($draft, 'output/lecture.html'),
            ])->save();

            return [
                'public_html'       => $publicHtml,
                'public_slides_dir' => $publicSlidesDir,
                'lesson_id'         => $lesson->id,
                'public_url'        => $publicUrl,
            ];
        });
    }

    private function resolveLesson(LectureDraft $draft, ?int $lessonId): Lesson
    {
        if ($lessonId !== null) {
            $lesson = Lesson::find($lessonId);
            if ($lesson === null) {
                throw new RuntimeException("Lesson #{$lessonId} не найден");
            }
            return $lesson;
        }

        if ($draft->lesson_id !== null) {
            $lesson = $draft->lesson;
            if ($lesson === null) {
                throw new RuntimeException("Lesson #{$draft->lesson_id} помечен в черновике, но не существует");
            }
            return $lesson;
        }

        // Создаём новый Lesson на основе меты черновика
        if ($draft->course_id === null) {
            throw new RuntimeException('Для создания нового урока нужно указать course_id в черновике');
        }

        $meta = $draft->meta ?? [];
        return Lesson::create([
            'title'         => $draft->title,
            'topic'         => $meta['lesson_title'] ?? null,
            'course_id'     => $draft->course_id,
            'lesson_date'   => now(),
            'youtube_url'   => $meta['video']['youtube'] ?? ($meta['youtube'] ?? null),
            'rutube_url'    => $meta['video']['rutube'] ?? ($meta['rutube'] ?? null),
            'is_published'  => true,
            'block_number'  => $meta['lesson_number'] ?? 1,
        ]);
    }
}
