<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Lesson;
use App\Services\HomeworkAutoOpener;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Бэкфилл `lessons.recording_attached_at` (H1935, issue #868).
 *
 * Штамп ставится хуком в Lesson::boot() ТОЛЬКО при сохранении урока, у которого
 * уже есть видео. Колонка появилась 27-07-2026, поэтому у всех уроков, чьи
 * видео-ссылки заполнили раньше и которых с тех пор никто не пересохранял,
 * штамп остался NULL: 1662 из 1667 на проде на 30-07-2026. Для автооткрытия ДЗ
 * пустой штамп неотличим от отсутствия записи — фича молча не делает ничего.
 *
 * ЧЕСТНОСТЬ ИСТОЧНИКА. Реального момента прикрепления записи в базе нет и
 * восстановить его неоткуда. Поэтому команда НЕ притворяется, что «нашла» его:
 * она ВОССТАНАВЛИВАЕТ опорную точку из `lesson_date` — даты, когда занятие
 * прошло. Это не наблюдение, а реконструкция, и она названа так и здесь, и в
 * выводе команды. Смысл при этом ровно тот, что задуман фичей: приём ДЗ
 * открывается на следующее утро после занятия.
 *
 * ЧЕГО КОМАНДА НЕ ДЕЛАЕТ, СОЗНАТЕЛЬНО:
 *  - не трогает `homework_enabled`, `homework_prompt`, `homework_auto_opened_at`
 *    и `homework_closed_at` — она не открывает приём и не может открыть;
 *  - не шлёт НИ ОДНОГО уведомления. У большинства этих уроков расчётный момент
 *    открытия давно в прошлом, и наивная рассылка ушла бы по всему архиву;
 *  - не перезаписывает уже проставленный штамп — только NULL.
 */
class BackfillRecordingStamp extends Command
{
    protected $signature = 'lessons:backfill-recording-stamp
        {--apply : Записать в БД. Без этого флага — только отчёт, ни одной записи}
        {--course=* : Ограничить слагами курсов (можно несколько)}
        {--limit= : Обработать не более N уроков (для осторожного первого прогона)}';

    protected $description = 'Восстанавливает lessons.recording_attached_at из lesson_date там, где он NULL, а запись есть (#868).';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;

        $query = $this->candidates();

        if ($courses = (array) $this->option('course')) {
            $courses = array_values(array_filter($courses));
            if ($courses !== []) {
                $query->whereHas('course', fn (Builder $c) => $c->whereIn('slug', $courses));
            }
        }

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('Уроков с записью и пустым штампом не найдено — бэкфилл не нужен.');

            return self::SUCCESS;
        }

        $this->warn('Значения ВОССТАНАВЛИВАЮТСЯ из lesson_date, а не восстанавливаются из наблюдений:');
        $this->warn('реального момента прикрепления записи в базе нет. Это реконструкция, не находка.');
        $this->newLine();

        $lessons = $query->orderBy('id');
        if ($limit !== null) {
            $lessons->limit($limit);
        }
        $lessons = $lessons->get();

        $rows = [];
        $noAnchor = 0;
        $written = 0;

        foreach ($lessons as $lesson) {
            $anchor = $this->anchorFor($lesson);
            if ($anchor === null) {
                $noAnchor++;

                continue;
            }

            $opensAt = HomeworkAutoOpener::opensAtFor($anchor);

            if ($apply) {
                // DB::table, а не модель: хук Lesson::boot() поставил бы «сейчас»,
                // затерев ровно то, что мы чиним. Затрагиваем ДВЕ колонки, и всё.
                DB::table('lessons')->where('id', $lesson->id)->update([
                    'recording_attached_at' => $anchor->format('Y-m-d H:i:s'),
                    'homework_opens_at' => $opensAt->format('Y-m-d H:i:s'),
                ]);
                $written++;
            }

            if (count($rows) < 15) {
                $rows[] = [
                    $lesson->id,
                    mb_substr((string) $lesson->title, 0, 42),
                    $anchor->format('d.m.Y'),
                    $opensAt->format('d.m.Y H:i'),
                ];
            }
        }

        $this->table(['Урок', 'Название', 'Опора (lesson_date)', 'Открытие'], $rows);
        if (count($lessons) > count($rows)) {
            $this->line(sprintf('… и ещё %d — показаны первые %d.', count($lessons) - count($rows), count($rows)));
        }

        if ($noAnchor > 0) {
            $this->warn(sprintf('%d урок(ов) пропущено: нет ни lesson_date, ни created_at.', $noAnchor));
        }

        $this->newLine();
        if ($apply) {
            $this->info(sprintf('Записано: %d из %d кандидатов. Приём ДЗ НЕ открыт ни у одного — этим занимается homework:auto-open.', $written, $total));
        } else {
            $this->info(sprintf('--dry-run по умолчанию: %d кандидат(ов). База не тронута, уведомления не отправлялись.', $total));
            $this->line('Записать: та же команда с --apply.');
        }

        return self::SUCCESS;
    }

    /** Уроки с записью и пустым штампом. Единственное определение — здесь. */
    private function candidates(): Builder
    {
        return Lesson::query()
            ->whereNull('recording_attached_at')
            ->where(function (Builder $q) {
                foreach (['youtube_url', 'rutube_url', 'video_url'] as $column) {
                    $q->orWhere(fn (Builder $w) => $w->whereNotNull($column)->where($column, '<>', ''));
                }
            });
    }

    /**
     * Опорная точка реконструкции: дата занятия, иначе дата создания строки.
     * Явно в Europe/Moscow — таймзона проекта прибита в config/app.php, но
     * зависеть от таймзоны сервера в денежно-расписаночной арифметике нельзя.
     */
    private function anchorFor(object $lesson): ?Carbon
    {
        if (filled($lesson->lesson_date)) {
            return Carbon::parse($lesson->lesson_date, 'Europe/Moscow')->startOfDay();
        }

        if (filled($lesson->created_at)) {
            return Carbon::parse($lesson->created_at)->setTimezone('Europe/Moscow');
        }

        return null;
    }
}
