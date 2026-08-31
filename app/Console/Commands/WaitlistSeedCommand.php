<?php

namespace App\Console\Commands;

use App\Models\CourseWaitlistItem;
use Illuminate\Console\Command;

/**
 * Сид «Списка ожидания» (MG 31-08-2026, таблица из чата + прод-история
 * docs/WAITLIST_SEED_26_PROPOSAL_01-09-2026.md). Идемпотентен: строки по slug.
 */
class WaitlistSeedCommand extends Command
{
    protected $signature = 'waitlist:seed {--dry-run : показать, что будет вставлено}';

    protected $description = 'Засеять 26 строк списка ожидания (MG 31-08-2026)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // [slug, курс, преподаватель, слот, earliest, min, блок₽, kind, paid_n(история)]
        $rows = [
            // Гасунс — Бюлер — два отдельных потока
            ['bueler-1-potok', 'Руководство по Бюлеру', 'Марцис Гасунс', null, '2027-10-01', 10, 8000, 'grammar', null],
            ['bueler-2-potok', 'Руководство по Бюлеру', 'Марцис Гасунс', null, '2027-10-01', 10, 8000, 'grammar', null],
            ['tsifrovaya-gramotnost', 'Цифровая грамотность', 'Марцис Гасунс', null, '2026-10-01', 10, 10000, 'other', null],
            ['vishnushasasranama', 'Вишнусахасранама', 'Марцис Гасунс', null, '2027-10-01', 10, 8000, 'other', 41],
            ['skazanie-o-nale', 'Сказание о Нале', 'Марцис Гасунс', null, '2027-10-01', 8, 8000, 'other', 166],
            // Костина
            ['nachalnyi-hindi-1', 'Начальный хинди', 'Екатерина Костина', null, '2027-09-15', 8, 8000, 'other', null],
            ['nachalnyi-hindi-2', 'Начальный хинди', 'Екатерина Костина', null, '2027-09-15', 8, 8000, 'other', null],
            ['nachalnyi-bengalskii', 'Начальный бенгальский', 'Екатерина Костина', null, '2027-09-15', 8, 8000, 'other', null],
            ['meghaduta-kalidasy', 'Мегхадута Калидасы', 'Екатерина Костина', null, '2027-10-15', 8, 6000, 'other', null],
            ['indiiskoe-kino', 'Индийское кино', 'Екатерина Костина', null, '2027-10-15', 8, 6000, 'other', null],
            // Демченко
            ['rudrashtakam-hindi', 'Рудраштакам (на хинди)', 'Максим Демченко', 'сб 17:00', '2026-04-13', 10, 10000, 'other', null],
            // Трефилова
            ['nachalnyi-sanskrit-trefilova', 'Начальный санскрит', 'Елена Трефилова', null, '2026-10-01', 8, 6000, 'grammar', null],
            ['sanskritskaya-prodlenka-intensiv', 'Санскритская продленка (летний интенсив)', 'Елена Трефилова', null, '2027-07-01', 8, 6000, 'other', null],
            ['likbez-po-lingvistike', 'Ликбез по лингвистике', 'Елена Трефилова', null, '2026-10-01', 8, 6000, 'other', 153],
            ['lingvisticheskie-zadachi', 'Лингвистические задачи', 'Елена Трефилова', null, '2026-10-01', 8, 6000, 'other', null],
            // Литвиненко
            ['kalligrafiya-devanagari', 'Каллиграфия деванагари', 'Ольга Литвиненко', null, '2026-10-01', 8, 6000, 'other', null],
            ['nachalnyi-sanskrit-litvinenko', 'Начальный санскрит', 'Ольга Литвиненко', null, '2026-09-15', 8, 6000, 'grammar', null],
            // Лейтан
            ['gitarthasangraha-abhinavagputy', 'Гитартхасанграха Абхинавагупты', 'Эдгар Лейтан', null, '2026-09-15', 8, 6000, 'other', null],
            // Леонов
            ['filosofiya-sankhyi', 'Философия санкхьи', 'Максим Леонов', 'пн 18:00', '2026-09-01', 8, 6000, 'other', null],
            ['indiiskii-epos', 'Индийский эпос: «Махабхарата» и «Рамаяна»', 'Максим Леонов', 'пн 18:00', '2026-09-01', 8, 6000, 'other', null],
            // Ворошилов
            ['kashmirskii-shivaizm', 'Кашмирский шиваизм', 'Максим Ворошилов', 'сб 13:00', '2026-09-01', 8, 6000, 'other', 156],
            // Лундышева
            ['putevoditel-po-buddizmu', 'Путеводитель по буддизму', 'Ольга Лундышева', null, null, 8, 4800, 'other', 39],
            ['bxagavadgita-po-rukopisyam', 'Бхагавадгита по рукописям', 'Ольга Лундышева', null, null, 8, 4800, 'other', null],
            // Щербак
            ['arkhitektura-i-iskusstvo-buddizma', 'Архитектура и искусство буддизма', 'Сергей Щербак', null, null, 8, 4800, 'other', 72],
            // Пахомов
            ['yoga-i-tantra', 'Йога и тантра', 'Сергей Пахомов', null, null, 8, 6000, 'other', 99],
            // 26-й: исторические ноты для повторов Кашмира
        ];

        $created = 0;
        $updated = 0;
        foreach ($rows as [$slug, $title, $teacher, $slot, $earliest, $min, $price, $kind, $paidN]) {
            $payload = [
                'course_title' => $title,
                'teacher_name' => $teacher,
                'slot' => $slot,
                'earliest_start_at' => $earliest,
                'min_payers' => $min,
                'block_price_rub' => $price,
                'kind' => $kind,
                'historical_paid_n' => $paidN,
                'is_listed' => true,
            ];
            $existing = CourseWaitlistItem::query()->where('slug', $slug)->first();
            if ($existing) {
                $updated++;
                if (! $dryRun) {
                    $existing->update($payload);
                }
            } else {
                $created++;
                if (! $dryRun) {
                    CourseWaitlistItem::create(array_merge($payload, ['slug' => $slug]));
                }
            }
        }

        // Кашмирский: исторические ноты отдельно (спад −41 %)
        if (! $dryRun) {
            CourseWaitlistItem::query()->where('slug', 'kashmirskii-shivaizm')->update([
                'historical_notes' => '1 поток 2025 — 156 оплат (338 491 ₽), 2 поток 2026 — 93 оплаты (166 570 ₽); спад −41 %',
            ]);
        }

        $this->info(($dryRun ? '[dry-run] ' : '')."создано: {$created}, обновлено: {$updated}");

        return self::SUCCESS;
    }
}
