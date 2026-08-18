<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Course;
use App\Services\Media\ModelImageWebpConverter;
use Illuminate\Support\Facades\Log;

/**
 * Первый из двух рубежей автоперевода обложек в WebP (H3082).
 *
 * Этот ловит обложку в тот же запрос, в котором её загрузили, — через какую бы
 * форму она ни пришла, лишь бы запись шла через Eloquent. Второй рубеж —
 * ежедневная `media:covers-to-webp`, она подбирает всё, что прошло мимо модели
 * (сиды, прямой SQL, будущий импортёр). Ни один из них не требует человека.
 *
 * Исключений не бросает: загрузка обложки не обязана падать из-за того, что GD
 * не осилил конкретный файл. Уборка попробует ещё раз ночью.
 */
class CourseCoverWebpObserver
{
    public function __construct(private readonly ModelImageWebpConverter $converter) {}

    public function saved(Course $course): void
    {
        if (! (bool) config('media.webp.enabled', true)) {
            return;
        }

        // Только когда обложка реально поменялась в этом сохранении, иначе
        // каждый апдейт курса дёргал бы диск впустую.
        //
        // wasRecentlyCreated проверяется ОТДЕЛЬНО и первым: syncChanges()
        // вызывается в performUpdate(), но не в performInsert(), поэтому у
        // только что вставленной строки wasChanged() всегда false — на одном
        // wasChanged() наблюдатель пропускал бы ровно первую загрузку обложки.
        if (! $course->wasRecentlyCreated && ! $course->wasChanged('image_path')) {
            return;
        }

        try {
            $this->converter->convert($course, 'image_path');
        } catch (\Throwable $e) {
            Log::warning('webp: cover convert failed on save', [
                'course_id' => $course->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
