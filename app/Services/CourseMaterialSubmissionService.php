<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseDesignAsset;
use App\Models\CourseMaterialSubmission;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Единственная точка записи заявок «Мои материалы» (H4325): препод шлёт
 * видео-анонс/бейдж 4:3/конспект через {@see submit()} — черновик, никогда не
 * трогает courses/course_design_assets напрямую. Публикует куратор через
 * {@see publish()} (или {@see setStatus()} со статусом published) — это и есть
 * гейт «заявка не публикует себя сама» (анти-цель H4325).
 */
class CourseMaterialSubmissionService
{
    /**
     * Джейл расширений картинки бейджа — тот же контракт, что и
     * CourseDesignAssetService (расширение из имени клиента + реальный MIME
     * оба обязаны быть из белого списка), продублирован намеренно: сервисы
     * пишут в РАЗНЫЕ таблицы (черновик vs витрина course_design_assets), общий
     * помощник добавил бы связность между ними без надобности.
     */
    private const IMAGE_EXT_BY_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    private const ALLOWED_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    /**
     * Создать или обновить ОТКРЫТУЮ (не published) заявку курса — повторная
     * отправка того же препода правит эту же строку, не плодит дубли, и это
     * и есть «обновил материалы», на которое обязана среагировать нотификация
     * куратору (мандат H4325). После публикации следующая отправка того же
     * препода заводит новый цикл — открытой строки уже нет.
     */
    public function submit(
        Course $course,
        User $teacher,
        ?string $videoAnnounceUrl,
        ?UploadedFile $badge,
        ?string $notes,
    ): CourseMaterialSubmission {
        /** @var CourseMaterialSubmission $submission */
        $submission = $course->materialSubmissions()->open()->latest('id')->first()
            ?? new CourseMaterialSubmission([
                'course_id' => $course->id,
                'status' => CourseMaterialSubmission::STATUS_ACCEPTED,
            ]);

        $submission->submitted_by_user_id = $teacher->id;
        $submission->video_announce_url = filled($videoAnnounceUrl) ? trim($videoAnnounceUrl) : null;
        $submission->notes = filled($notes) ? $notes : null;

        $oldDisk = $submission->badge_disk;
        $oldPath = $submission->badge_path;
        $written = null;

        if ($badge) {
            $extension = $this->assertAllowedImage($badge);
            $disk = (string) config('design_assets.image_disk', 'public');
            $path = $badge->storeAs(
                'course-material-submissions/'.$course->id,
                'badge-'.Str::random(8).'.'.$extension,
                ['disk' => $disk],
            );
            $written = [$disk, $path];
            [$width, $height] = $this->dimensions($badge);

            $submission->badge_disk = $disk;
            $submission->badge_path = $path;
            $submission->badge_original_name = $badge->getClientOriginalName();
            $submission->badge_size = $badge->getSize();
            $submission->badge_mime = $badge->getMimeType();
            $submission->badge_width = $width;
            $submission->badge_height = $height;
        }

        try {
            $submission->save();
        } catch (\Throwable $e) {
            if ($written) {
                Storage::disk($written[0])->delete($written[1]);
            }

            throw $e;
        }

        // Замещённый файл чистим только после успешной записи строки.
        if ($written && filled($oldPath) && $oldPath !== $submission->badge_path) {
            Storage::disk((string) $oldDisk)->delete((string) $oldPath);
        }

        app(CuratorNotifier::class)->materialsSubmitted($submission);

        return $submission;
    }

    /**
     * Перевести заявку в статус очереди. published — особый случай, реально
     * публикует материалы курса, см. {@see publish()}.
     */
    public function setStatus(CourseMaterialSubmission $submission, string $status, User $curator): CourseMaterialSubmission
    {
        if (! array_key_exists($status, CourseMaterialSubmission::STATUSES)) {
            throw new InvalidArgumentException("Неизвестный статус заявки: {$status}");
        }

        if ($submission->isPublished()) {
            throw new InvalidArgumentException('Заявка уже опубликована — новый цикл начинается со следующей отправки препода.');
        }

        if ($status === CourseMaterialSubmission::STATUS_PUBLISHED) {
            return $this->publish($submission, $curator);
        }

        $submission->status = $status;
        $submission->reviewed_by_user_id = $curator->id;
        $submission->reviewed_at = now();
        $submission->save();

        return $submission;
    }

    /**
     * Публикационный гейт куратора: переносит присланное в реальные поля
     * курса. Пустое поле заявки НЕ затирает уже опубликованное значение курса
     * (препод мог прислать только конспект, не трогая видео) — заявка это
     * набор ПРЕДЛОЖЕНИЙ, а не полная перезапись карточки курса.
     */
    public function publish(CourseMaterialSubmission $submission, User $curator): CourseMaterialSubmission
    {
        if ($submission->isPublished()) {
            return $submission;
        }

        $newBadgePath = null;
        $imageDisk = (string) config('design_assets.image_disk', 'public');

        if ($submission->hasBadge()) {
            $extension = pathinfo((string) $submission->badge_path, PATHINFO_EXTENSION) ?: 'jpg';
            $newBadgePath = (string) config('design_assets.image_dir', 'course-design')
                .'/'.$submission->course_id
                .'/'.CourseDesignAsset::slugForFormat('4:3').'-'.Str::random(8).'.'.$extension;

            Storage::disk($imageDisk)->copy((string) $submission->badge_path, $newBadgePath);
        }

        try {
            $submission = DB::transaction(function () use ($submission, $curator, $newBadgePath, $imageDisk): CourseMaterialSubmission {
                $course = $submission->course()->lockForUpdate()->first() ?? $submission->course;

                if (filled($submission->video_announce_url)) {
                    $course->video_announce_url = $submission->video_announce_url;
                }
                if (filled($submission->notes)) {
                    $course->teacher_notes = $submission->notes;
                }
                $course->save();

                $oldBadge = null;
                if ($newBadgePath !== null) {
                    $oldBadge = CourseDesignAsset::query()
                        ->where('course_id', $course->id)
                        ->where('format', '4:3')
                        ->first();

                    CourseDesignAsset::updateOrCreate(
                        ['course_id' => $course->id, 'format' => '4:3'],
                        [
                            'disk' => $imageDisk,
                            'path' => $newBadgePath,
                            'original_name' => $submission->badge_original_name,
                            'size' => $submission->badge_size,
                            'mime' => $submission->badge_mime,
                            'width' => $submission->badge_width,
                            'height' => $submission->badge_height,
                            'uploaded_by_user_id' => $submission->submitted_by_user_id,
                        ],
                    );
                }

                $submission->status = CourseMaterialSubmission::STATUS_PUBLISHED;
                $submission->reviewed_by_user_id = $curator->id;
                $submission->reviewed_at = now();
                $submission->published_at = now();
                $submission->save();

                if ($oldBadge && filled($oldBadge->path) && $oldBadge->path !== $newBadgePath) {
                    Storage::disk($oldBadge->disk)->delete($oldBadge->path);
                }

                return $submission;
            });
        } catch (\Throwable $e) {
            if ($newBadgePath !== null) {
                Storage::disk($imageDisk)->delete($newBadgePath);
            }

            throw $e;
        }

        return $submission;
    }

    private function assertAllowedImage(UploadedFile $file): string
    {
        $clientExtension = strtolower($file->getClientOriginalExtension());

        if (! in_array($clientExtension, self::ALLOWED_IMAGE_EXTENSIONS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Файл «%s» отклонён: разрешены только картинки JPG, JPEG, PNG, WebP или GIF.',
                $file->getClientOriginalName() ?: 'без имени',
            ));
        }

        $mime = (string) $file->getMimeType();

        if (! isset(self::IMAGE_EXT_BY_MIME[$mime])) {
            throw new InvalidArgumentException(sprintf(
                'Содержимое файла «%s» не похоже на JPG, PNG, WebP или GIF.',
                $file->getClientOriginalName() ?: 'без имени',
            ));
        }

        return self::IMAGE_EXT_BY_MIME[$mime];
    }

    /** @return array{0: ?int, 1: ?int} */
    private function dimensions(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if ($path === false || ! is_readable($path)) {
            return [null, null];
        }

        $size = @getimagesize($path);
        if ($size === false) {
            return [null, null];
        }

        return [(int) $size[0], (int) $size[1]];
    }
}
