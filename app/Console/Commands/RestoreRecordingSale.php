<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\Tariff;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Вернуть курс-ЗАПИСЬ в продажу — обратный ход к `catalog:deactivate-dormant-tariffs`
 * (H3807, рулинг MG 31-08-2026 «вернуть в продажу»).
 *
 * Случай, ради которого написано: 31-08-2026 гигиеническая чистка погасила пять
 * тарифов курса 327 «Йога-сутры Патанджали (1 поток, 2025) в записи» — курса,
 * который купили **129 раз**. Формально всё верно: курс был скрыт с витрины,
 * тарифы висели предложением к несуществующей странице. По сути товар сняли с
 * продажи побочным эффектом уборки, а не решением.
 *
 * Область намеренно узкая: команда работает ТОЛЬКО с курсом, у которого
 * проставлен `recording_of_course_id`. Это ровно то, о чём был рулинг — запись
 * внутри карточки живого потока, — и это не даёт команде стать универсальным
 * «включить любой товар обратно».
 *
 * Тарифы НЕ угадываются. Без `--tariff` команда печатает погашенные тарифы с
 * датой их выключения и отказывается что-либо включать: тариф, погашенный в
 * апреле человеком, и тариф, погашенный сегодня уборкой, в базе неотличимы, а
 * включить первый значит вернуть в продажу то, что сняли обдуманно.
 */
class RestoreRecordingSale extends Command
{
    protected $signature = 'catalog:restore-recording-sale
        {course : id или slug курса-записи}
        {--tariff=* : id тарифа, который включить обратно (можно несколько)}
        {--apply : Выполнить (без флага — только план)}';

    protected $description = 'Вернуть курс-запись в продажу: показать страницу и включить НАЗВАННЫЕ тарифы. Доступ купивших не трогает.';

    public function handle(): int
    {
        $ref = (string) $this->argument('course');

        $course = ctype_digit($ref)
            ? Course::query()->find((int) $ref)
            : Course::query()->where('slug', $ref)->first();

        if ($course === null) {
            $this->error("Курс не найден: {$ref}");

            return self::FAILURE;
        }

        if ($course->recording_of_course_id === null) {
            $this->error(sprintf(
                'Курс %d не назван записью другого курса. Эта команда — про записи внутри карточки живого потока; '
                .'для обычного товара видимость и тарифы меняются в админке, осознанно.',
                $course->id,
            ));

            return self::FAILURE;
        }

        $live = $course->recordingOf;
        $requested = array_map('intval', (array) $this->option('tariff'));

        $inactive = $course->tariffs()->where('is_active', false)->orderBy('id')->get();
        $unknown = array_diff($requested, $inactive->pluck('id')->map(fn ($id) => (int) $id)->all());

        if ($unknown !== []) {
            $this->error('Не погашенные тарифы этого курса: '.implode(', ', $unknown).' — включать нечего.');

            return self::FAILURE;
        }

        $this->line(sprintf('<comment>Курс %d — %s</comment>', $course->id, $course->title));
        $this->line(sprintf(
            '  запись курса %d «%s» · оплат %d · записано %d',
            $live?->id, $live?->title, $course->payments()->paid()->count(), $course->users()->count(),
        ));
        $this->line($course->is_visible
            ? '  страница: уже открыта'
            : '  страница: '.($this->option('apply') ? 'открыта' : 'будет открыта').' (is_visible → true)');

        if ($inactive->isNotEmpty()) {
            $this->newLine();
            $this->line('<comment>Погашенные тарифы</comment> (включаются только названные через --tariff)');
            $this->table(
                ['id', 'Тариф', 'Ключ доступа', 'Цена', 'Погашен', 'Включаем'],
                $inactive->map(fn (Tariff $t): array => [
                    $t->id,
                    mb_substr((string) $t->title, 0, 34),
                    $t->accessKey(),
                    $t->price,
                    (string) $t->updated_at,
                    in_array((int) $t->id, $requested, true) ? 'да' : '—',
                ])->all(),
            );
        }

        if ($requested === []) {
            $this->newLine();
            $this->warn('Ни один тариф не назван — страница откроется без единого предложения о покупке.');
            $this->line('  Назовите их явно: --tariff=4342 --tariff=4343 …');
        }

        if (! $this->option('apply')) {
            $this->newLine();
            $this->info('Сухой прогон: ничего не изменено. Повторите с --apply.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($course, $requested): void {
            $course->forceFill(['is_visible' => true, 'is_active' => true])->save();

            if ($requested !== []) {
                Tariff::query()
                    ->where('course_id', $course->id)
                    ->whereIn('id', $requested)
                    ->update(['is_active' => true]);
            }
        });

        $this->newLine();
        $this->info(sprintf(
            'Готово: тарифов включено %d. Второй карточки в каталоге не появится — курс остаётся записью курса %d, canonical на него же.',
            count($requested),
            $live?->id,
        ));

        return self::SUCCESS;
    }
}
