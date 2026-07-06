<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Intake;
use App\Models\User;
use App\Models\WaitlistEntry;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Импорт ручной Google-таблицы набора в waitlist_entries.
 *
 * Колонки таблицы (заголовок обязателен, порядок произвольный — матчим по имени):
 *   Имя · Никнейм в тукане · Набор · Время и дни · Уровень · Примечания · Рассылка · Ответ
 *
 * Возвратные студенты авто-линкуются по @username (saving-хук модели). Отчёт
 * показывает «сматчено с аккаунтом / новых». Идемпотентно: повторный прогон
 * находит строку по (нормализованный username | имя) внутри набора и обновляет.
 */
class ImportWaitlistSheet extends Command
{
    protected $signature = 'waitlist:import
                            {path : Путь к CSV-файлу таблицы набора}
                            {--dry-run : Прогнать без записи в БД}';

    protected $description = 'Импорт таблицы набора (CSV) в waitlist_entries с авто-линком возвратных студентов';

    /** Синонимы заголовков → каноническое поле. Всё нормализуется (lower, без пунктуации). */
    private const HEADER_MAP = [
        'имя' => 'name',
        'никнейм в тукане' => 'telegram_username',
        'никнейм' => 'telegram_username',
        'ник' => 'telegram_username',
        'username' => 'telegram_username',
        'набор' => 'intake',
        'время и дни' => 'preferred_schedule',
        'время' => 'preferred_schedule',
        'расписание' => 'preferred_schedule',
        'уровень' => 'level',
        'примечания' => 'notes',
        'примечание' => 'notes',
        'рассылка' => 'outreach',
        'ответ' => 'reply',
        'часовой пояс' => 'timezone_note',
        'пояс' => 'timezone_note',
    ];

    /** @var array<string,int>|null Карта [mb_strtolower(label) => id] всех наборов. */
    private ?array $intakeMap = null;

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        $dryRun = (bool) $this->option('dry-run');

        if (! is_file($path)) {
            $this->error("Файл не найден: {$path}");

            return self::FAILURE;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            $this->error("Не удалось открыть файл: {$path}");

            return self::FAILURE;
        }

        $rawHeader = fgetcsv($handle);
        if ($rawHeader === false) {
            fclose($handle);
            $this->error('Пустой CSV.');

            return self::FAILURE;
        }

        // Позиция каждой колонки в строке по каноническому полю.
        $columns = $this->mapHeader($rawHeader);
        if (! isset($columns['name'])) {
            fclose($handle);
            $this->error('В заголовке не найдена колонка «Имя».');

            return self::FAILURE;
        }

        $this->info($dryRun ? 'Режим: dry-run (без записи в БД)' : 'Режим: реальный импорт');
        $this->newLine();

        $stats = ['created' => 0, 'updated' => 0, 'matched_account' => 0, 'skipped_blank' => 0];
        $missingIntakes = []; // label => count

        DB::beginTransaction();

        while (($row = fgetcsv($handle)) !== false) {
            $name = trim((string) ($row[$columns['name']] ?? ''));
            if ($name === '') {
                $stats['skipped_blank']++;

                continue;
            }

            $username = User::normalizeTelegramUsername($this->cell($row, $columns, 'telegram_username'));
            $intakeLabel = trim((string) $this->cell($row, $columns, 'intake'));
            $intakeId = $this->resolveIntakeId($intakeLabel, $missingIntakes);

            $attrs = [
                'name' => $name,
                'telegram_username' => $username,
                'intake_id' => $intakeId,
                'level' => $this->parseLevel($this->cell($row, $columns, 'level')),
                'preferred_schedule' => $this->nullableCell($row, $columns, 'preferred_schedule'),
                'timezone_note' => $this->nullableCell($row, $columns, 'timezone_note'),
                'notes' => $this->nullableCell($row, $columns, 'notes'),
                'last_outreach_at' => $this->parseDate($this->cell($row, $columns, 'outreach')),
                'last_reply_at' => $this->parseDate($this->cell($row, $columns, 'reply')),
            ];

            $existing = $this->findExisting($username, $name, $intakeId);

            if ($existing !== null) {
                if (! $dryRun) {
                    $existing->fill($attrs)->save();
                }
                $stats['updated']++;
                $entry = $existing->fresh() ?? $existing;
            } else {
                $entry = new WaitlistEntry($attrs);
                if (! $dryRun) {
                    $entry->save();
                }
                $stats['created']++;
            }

            // saving-хук проставляет user_id, если @username совпал с аккаунтом.
            if ($entry->user_id !== null || (! $dryRun && $entry->fresh()?->user_id !== null)) {
                $stats['matched_account']++;
            } elseif ($dryRun && $username !== null && WaitlistEntry::resolveStudentId($username) !== null) {
                $stats['matched_account']++;
            }
        }

        fclose($handle);

        if ($dryRun) {
            DB::rollBack();
        } else {
            DB::commit();
        }

        $this->renderSummary($stats, $missingIntakes, $dryRun);

        return self::SUCCESS;
    }

    /**
     * @param  array<int,string>  $rawHeader
     * @return array<string,int>  канонич. поле => индекс колонки
     */
    private function mapHeader(array $rawHeader): array
    {
        $columns = [];
        foreach ($rawHeader as $i => $title) {
            $key = $this->normalizeHeader((string) $title);
            if (isset(self::HEADER_MAP[$key]) && ! isset($columns[self::HEADER_MAP[$key]])) {
                $columns[self::HEADER_MAP[$key]] = $i;
            }
        }

        return $columns;
    }

    private function normalizeHeader(string $title): string
    {
        // Снимаем BOM, приводим к нижнему регистру, схлопываем пробелы/пунктуацию.
        $title = str_starts_with($title, "\xEF\xBB\xBF") ? substr($title, 3) : $title;
        $title = mb_strtolower(trim($title));
        $title = preg_replace('~[^\p{L}\p{N}\s]+~u', ' ', $title) ?? $title;
        $title = preg_replace('~\s+~u', ' ', $title) ?? $title;

        return trim($title);
    }

    /**
     * @param  array<int,string>  $row
     * @param  array<string,int>  $columns
     */
    private function cell(array $row, array $columns, string $field): ?string
    {
        if (! isset($columns[$field])) {
            return null;
        }

        $value = trim((string) ($row[$columns[$field]] ?? ''));

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<int,string>  $row
     * @param  array<string,int>  $columns
     */
    private function nullableCell(array $row, array $columns, string $field): ?string
    {
        return $this->cell($row, $columns, $field);
    }

    private function parseLevel(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = mb_strtolower($raw);

        return match (true) {
            str_contains($value, 'продолж') => 'continuing',
            str_contains($value, 'нович') => 'novice',
            default => null,
        };
    }

    private function resolveIntakeId(string $label, array &$missingIntakes): ?int
    {
        if ($label === '') {
            return null;
        }

        if (array_key_exists($label, $this->intakeCache)) {
            $id = $this->intakeCache[$label];
        } else {
            $id = Intake::query()->whereRaw('LOWER(label) = ?', [mb_strtolower($label)])->value('id');
            $this->intakeCache[$label] = $id !== null ? (int) $id : null;
            $id = $this->intakeCache[$label];
        }

        if ($id === null) {
            $missingIntakes[$label] = ($missingIntakes[$label] ?? 0) + 1;
        }

        return $id;
    }

    private function findExisting(?string $username, string $name, ?int $intakeId): ?WaitlistEntry
    {
        $query = WaitlistEntry::query();

        if ($username !== null) {
            return $query->where('telegram_username', $username)->first();
        }

        // Без username дедупим по имени в пределах одного набора.
        return $query
            ->where('name', $name)
            ->where('intake_id', $intakeId)
            ->first();
    }

    private function parseDate(?string $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        foreach (['d.m.Y', 'd.m.y', 'd.m', 'Y-m-d'] as $format) {
            $carbon = Carbon::createFromFormat($format, $value);
            if ($carbon !== false) {
                // «d.m» без года — считаем текущий год.
                return $carbon->startOfDay();
            }
        }

        return null;
    }

    /**
     * @param  array<string,int>  $stats
     * @param  array<string,int>  $missingIntakes
     */
    private function renderSummary(array $stats, array $missingIntakes, bool $dryRun): void
    {
        $suffix = $dryRun ? ' (dry-run)' : '';

        $this->newLine();
        $this->line('<fg=green>Сводка'.$suffix.'</>');
        $this->table(
            ['Действие', 'Кол-во'],
            [
                ['Создано новых заявок', $stats['created']],
                ['Обновлено существующих', $stats['updated']],
                ['Сматчено с аккаунтом (возвратные)', $stats['matched_account']],
                ['Пропущено пустых строк', $stats['skipped_blank']],
            ],
        );

        if (! empty($missingIntakes)) {
            $this->newLine();
            $this->line('<fg=yellow>Наборы из таблицы, которых нет в БД (label → строк):</>');
            ksort($missingIntakes);
            foreach ($missingIntakes as $label => $count) {
                $this->line("  • {$label} — {$count}");
            }
            $this->line('  Создайте эти наборы в админке и перезапустите импорт для привязки.');
        }
    }
}
