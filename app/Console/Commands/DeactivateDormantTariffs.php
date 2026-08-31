<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Tariff;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Погасить активные тарифы курса, снятого с витрины (H3773, остаток).
 *
 * Случай, ради которого написано: курс 327 «Йога-сутры Патанджали (1 поток,
 * 2025) в записи» — скрыт с витрины, `/k/…` отдаёт 404, и при этом держит пять
 * АКТИВНЫХ тарифов. Купить по ним нельзя (страницы нет), но в каталоге они
 * числятся живым предложением: витрина закрытого клуба, которой не существует.
 *
 * Почему не {@see RetireCatalogShell}: та сводит ОБОЛОЧКУ (курс без единой
 * собственной строки) на живой курс семьи и переносит записи. 327 не оболочка —
 * у него свои четыре блока, 129 оплат и 43 записанных, — и перенос записей
 * отнял бы у людей ровно тот материал, который они купили. `retire-shell` на
 * нём честно отказывается; эта команда делает единственное, что здесь уместно.
 *
 * **Доступ не затрагивается.** Он считается пересечением ключей `payments.tariff`
 * с {@see Lesson::unlockingKeys()}; `tariffs.is_active` в этой цепочке
 * не участвует вообще, и ни один путь выдачи доступа по нему не фильтрует.
 * Погашенный тариф убирает предложение из каталога, а не покупку у купившего.
 *
 * Гарантия сужена намеренно: **видимый курс команда не трогает**. На витрине
 * гашение тарифов — это снятие товара с продажи, решение продуктовое, а не
 * гигиеническое, и принимать его молча командой нельзя.
 */
class DeactivateDormantTariffs extends Command
{
    protected $signature = 'catalog:deactivate-dormant-tariffs
        {course : id или slug курса, снятого с витрины}
        {--apply : Выполнить (без флага — только план)}';

    protected $description = 'Погасить активные тарифы курса, скрытого с витрины. Доступ купивших не трогает; видимый курс отклоняет.';

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

        if ((bool) $course->is_visible) {
            $this->error(sprintf(
                'Курс %d «%s» ВИДЕН на витрине. Гашение тарифов у видимого курса — снятие товара с продажи, '
                .'а это продуктовое решение: сначала скройте курс, если он действительно не продаётся.',
                $course->id,
                $course->title,
            ));

            return self::FAILURE;
        }

        $tariffs = $course->tariffs()->where('is_active', true)->orderBy('id')->get();

        if ($tariffs->isEmpty()) {
            $this->info(sprintf('У курса %d активных тарифов нет — гасить нечего.', $course->id));

            return self::SUCCESS;
        }

        $this->line(sprintf('<comment>Курс %d — %s</comment>', $course->id, $course->title));
        $this->line(sprintf(
            '  скрыт с витрины · оплат %d · записано %d — доступ этих людей НЕ затрагивается',
            $course->payments()->paid()->count(),
            $course->users()->count(),
        ));

        $this->table(
            ['id', 'Тариф', 'Ключ доступа', 'Цена'],
            $tariffs->map(fn (Tariff $t): array => [
                $t->id,
                mb_substr((string) $t->title, 0, 40),
                $t->accessKey(),
                $t->price,
            ])->all(),
        );

        if (! $this->option('apply')) {
            $this->newLine();
            $this->info('Сухой прогон: ничего не изменено. Повторите с --apply.');

            return self::SUCCESS;
        }

        $touched = DB::transaction(fn (): int => Tariff::query()
            ->where('course_id', $course->id)
            ->where('is_active', true)
            ->update(['is_active' => false]));

        $this->newLine();
        $this->info(sprintf('Погашено тарифов: %d. Оплаты, записи и доступ не тронуты.', $touched));

        return self::SUCCESS;
    }
}
