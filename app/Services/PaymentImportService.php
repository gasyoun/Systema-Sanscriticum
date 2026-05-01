<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

final class PaymentImportService
{
    /** Поля БД, в которые мапим колонки. Ключ → human-label. */
    public const FIELDS = [
        'user'           => 'Студент (поиск)',
        'amount'         => 'Сумма',
        'date'           => 'Дата оплаты',
        'start_block'    => 'Блок: с (опц.)',
        'end_block'      => 'Блок: по (опц.)',
        'tariff'         => 'Тариф (опц.)',
        'transaction_id' => 'Примечание (опц.)',
    ];

    /** Обязательные поля. */
    public const REQUIRED_FIELDS = ['user', 'amount'];

    /**
     * Читает только заголовок файла (первая строка) и возвращает массив:
     * ['A' => 'Имя колонки 1', 'B' => 'Имя колонки 2', ...]
     *
     * @return array<string, string>
     */
    public function readHeaders(string $absolutePath): array
    {
        $rows = Excel::toCollection(null, $absolutePath)->first() ?? collect();
        $first = $rows->first();

        if (! $first) {
            return [];
        }

        $headers = [];
        foreach ($first->toArray() as $index => $value) {
            $letter = $this->indexToLetter((int) $index);
            $label  = trim((string) $value);
            $headers[$letter] = $label !== '' ? "{$letter}: {$label}" : "{$letter}: (без названия)";
        }

        return $headers;
    }

    /**
     * Читает первые N строк данных (после заголовка) для превью.
     *
     * @return array<int, array<string, mixed>>
     */
    public function readPreview(string $absolutePath, int $limit = 3): array
    {
        $rows = Excel::toCollection(null, $absolutePath)->first() ?? collect();
        return $rows->slice(1, $limit)->values()->map->toArray()->toArray();
    }

    /**
     * Главный метод импорта.
     *
     * @param  array<string, string>  $mapping  ['user'=>'B', 'amount'=>'F', ...] — какая буква колонки соответствует полю
     * @param  string  $userLookupField  По какому полю User-а искать: name | email | phone
     * @return array<string, mixed>
     */
    public function import(
        string $absolutePath,
        Course $course,
        array $mapping,
        string $userLookupField = 'name',
    ): array {
        $this->validateMapping($mapping);
        $this->validateUserLookupField($userLookupField);

        // Кэш юзеров по выбранному полю поиска
        $users = User::whereNotNull($userLookupField)
            ->pluck('id', $userLookupField)
            ->toArray();

        // Кэш существующих оплат для дедупа
        $existingKeys = DB::table('payments')
            ->where('course_id', $course->id)
            ->select(['user_id', 'amount', 'start_block', 'end_block', 'created_at'])
            ->get()
            ->mapWithKeys(fn ($p) => [
                $this->dedupKey(
                    (int) $p->user_id,
                    (float) $p->amount,
                    $p->start_block !== null ? (int) $p->start_block : null,
                    $p->end_block !== null ? (int) $p->end_block : null,
                    Carbon::parse($p->created_at),
                ) => true,
            ])
            ->toArray();

        $rows = Excel::toCollection(null, $absolutePath)->first() ?? collect();

        $stats = [
            'total_rows'    => 0,
            'inserted'      => 0,
            'duplicates'    => 0,
            'no_user'       => 0,
            'negative'      => 0,
            'empty'         => 0,
            'missing_users' => [],
        ];

        $batch = [];
        $isFirst = true;

        foreach ($rows as $row) {
            if ($isFirst) { $isFirst = false; continue; }

            $stats['total_rows']++;
            $rowArr = $row->toArray();

            $studentKey = trim((string) $this->getCell($rowArr, $mapping['user']));
            if ($studentKey === '') {
                $stats['empty']++;
                continue;
            }

            $amount = $this->cleanNumber((string) $this->getCell($rowArr, $mapping['amount']));
            if ($amount < 0) {
                $stats['negative']++;
                continue;
            }
            if ($amount === 0.0) {
                $stats['empty']++;
                continue;
            }

            $userId = $users[$studentKey] ?? null;
            if (! $userId) {
                $stats['no_user']++;
                if (! in_array($studentKey, $stats['missing_users'], true)) {
                    $stats['missing_users'][] = $studentKey;
                }
                continue;
            }

            $startBlock = isset($mapping['start_block'])
                ? (int) $this->cleanNumber((string) $this->getCell($rowArr, $mapping['start_block']))
                : 0;
            $endBlock = isset($mapping['end_block'])
                ? (int) $this->cleanNumber((string) $this->getCell($rowArr, $mapping['end_block']))
                : 0;

            $dateRaw = isset($mapping['date'])
                ? trim((string) $this->getCell($rowArr, $mapping['date']))
                : '';
            $parsedDate = now();
            if ($dateRaw !== '') {
                try { $parsedDate = Carbon::parse($dateRaw); } catch (\Throwable) {}
            }

            $tariffOverride = isset($mapping['tariff'])
                ? trim((string) $this->getCell($rowArr, $mapping['tariff']))
                : '';
            $noteOverride = isset($mapping['transaction_id'])
                ? trim((string) $this->getCell($rowArr, $mapping['transaction_id']))
                : '';

            foreach ($this->buildPaymentRows(
                userId:    $userId,
                courseId:  $course->id,
                amount:    $amount,
                startBlock: $startBlock,
                endBlock:   $endBlock,
                date:       $parsedDate,
                tariffOverride: $tariffOverride,
                noteOverride:   $noteOverride,
            ) as $payment) {
                $key = $this->dedupKey(
                    $payment['user_id'],
                    (float) $payment['amount'],
                    $payment['start_block'],
                    $payment['end_block'],
                    $payment['created_at'],
                );

                if (isset($existingKeys[$key])) {
                    $stats['duplicates']++;
                    continue;
                }

                $existingKeys[$key] = true;

                $payment['created_at'] = $payment['created_at']->toDateTimeString();
                $payment['updated_at'] = $payment['updated_at']->toDateTimeString();

                $batch[] = $payment;
                $stats['inserted']++;
            }

            if (count($batch) >= 500) {
                DB::table('payments')->insert($batch);
                $batch = [];
            }
        }

        if (! empty($batch)) {
            DB::table('payments')->insert($batch);
        }

        return $stats;
    }

    /**
     * Преобразует A→0, B→1, ..., Z→25, AA→26, AB→27, ...
     */
    public function letterToIndex(string $letter): int
    {
        $letter = strtoupper(trim($letter));
        $index = 0;
        for ($i = 0, $len = strlen($letter); $i < $len; $i++) {
            $index = $index * 26 + (ord($letter[$i]) - ord('A') + 1);
        }
        return $index - 1;
    }

    /**
     * Преобразует 0→A, 1→B, ..., 26→AA
     */
    public function indexToLetter(int $index): string
    {
        $letter = '';
        $index++;
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $index = (int) (($index - $mod) / 26);
        }
        return $letter;
    }

    /**
     * Достаёт значение из строки по букве колонки.
     */
    private function getCell(array $row, string $letter): mixed
    {
        return $row[$this->letterToIndex($letter)] ?? null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildPaymentRows(
        int $userId,
        int $courseId,
        float $amount,
        int $startBlock,
        int $endBlock,
        Carbon $date,
        string $tariffOverride = '',
        string $noteOverride = '',
    ): array {
        $rows = [];
        $note = $noteOverride !== '' ? mb_substr($noteOverride, 0, 65000) : 'Импорт через админку';

        if ($startBlock > 0 && $endBlock >= $startBlock) {
            $blocksCount = $endBlock - $startBlock + 1;
            $perBlock = $amount / $blocksCount;

            for ($i = $startBlock; $i <= $endBlock; $i++) {
                $rows[] = [
                    'user_id'        => $userId,
                    'course_id'      => $courseId,
                    'amount'         => round($perBlock, 2),
                    'tariff'         => $tariffOverride !== '' ? $tariffOverride : "block_{$i}",
                    'status'         => 'paid',
                    'start_block'    => $i,
                    'end_block'      => $i,
                    'transaction_id' => $blocksCount > 1
                        ? "{$note} (мульти-блок {$startBlock}-{$endBlock})"
                        : $note,
                    'created_at'     => $date->copy(),
                    'updated_at'     => $date->copy(),
                ];
            }
        } else {
            $rows[] = [
                'user_id'        => $userId,
                'course_id'      => $courseId,
                'amount'         => $amount,
                'tariff'         => $tariffOverride !== '' ? $tariffOverride : 'full',
                'status'         => 'paid',
                'start_block'    => null,
                'end_block'      => null,
                'transaction_id' => $note,
                'created_at'     => $date->copy(),
                'updated_at'     => $date->copy(),
            ];
        }

        return $rows;
    }

    private function cleanNumber(string $value): float
    {
        $value = str_replace(',', '.', trim($value));
        $value = preg_replace('/[^\d.-]/', '', $value);
        return $value === '' ? 0.0 : (float) $value;
    }

    private function dedupKey(
        int $userId,
        float $amount,
        ?int $startBlock,
        ?int $endBlock,
        Carbon $createdAt,
    ): string {
        return implode('|', [
            $userId,
            number_format($amount, 2, '.', ''),
            $startBlock ?? 'null',
            $endBlock ?? 'null',
            $createdAt->format('Y-m-d H:i:s'),
        ]);
    }

    private function validateMapping(array $mapping): void
    {
        foreach (self::REQUIRED_FIELDS as $required) {
            if (empty($mapping[$required])) {
                throw new \InvalidArgumentException("Не задана колонка для обязательного поля: {$required}");
            }
        }
    }

    private function validateUserLookupField(string $field): void
    {
        if (! in_array($field, ['name', 'email', 'phone'], true)) {
            throw new \InvalidArgumentException("Недопустимое поле поиска студента: {$field}");
        }
    }
}