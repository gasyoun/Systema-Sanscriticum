<?php

declare(strict_types=1);

namespace App\Services\Reconciliation;

use App\Models\MoneyBankStatementCredit as Credit;
use App\Support\Kopecks;
use Carbon\CarbonImmutable;

/**
 * H5480 (P3b): разбор CSV выписки счёта Точки в строки ЗАЧИСЛЕНИЙ.
 *
 * Порт разбора из Uprava `tools/tochka_credits_paid_no_access_sensor.py`
 * (H4645) — тот же контракт, чтобы сенсор и импортёр видели одни и те же
 * строки: два признанных заголовка, неизвестный — ОТКАЗ (никогда не «молча
 * пропустить»), сумма `1 234,56`/`1234.56`, дата `ДД.ММ.ГГГГ`, кодировка
 * utf-8-sig или cp1251.
 *
 * row_hash = sha256(№ документа|дата|сумма|назначение) — та же формула, что в
 * сенсоре: повторный импорт пересекающихся выписок не удваивает строки.
 */
final class TochkaCreditStatementParser
{
    /** Направления, которые считаются зачислением. */
    public const INCOMING = ['Входящий', 'Зачисление'];

    public const SHAPE_ACCOUNT = 'account';

    public const SHAPE_CANONICAL = 'canonical-credits';

    private const CANONICAL_HEADER = ['date', 'amount', 'currency', 'payer', 'description'];

    private const RE_ORDER = '/Заказ\s*№\s*(\d+)/u';

    private const RE_QR = '/QR\s*коду\s*ID\s+([A-Za-z0-9]+)/u';

    private const RE_QR_KIND = '/QR\s*коду|СБП|Система быстрых платежей/iu';

    /**
     * Эквайринг карт приходит ДНЕВНЫМ АГРЕГАТОМ (одна строка на много оплат,
     * уже за вычетом комиссии) — измерено H4645: 111 из 219 несопоставленных
     * строк / ₽1 079 364. Сопоставлять такую строку с одной оплатой нельзя.
     */
    private const RE_CARD_AGGREGATE = '/по договору об обслуживании держателей платежных карт|эквайринг/iu';

    /**
     * @return array{rows: list<array<string, mixed>>, skipped: int, shape: string}
     *
     * @throws StatementFormatError неизвестный заголовок / нечитаемая кодировка
     */
    public function parse(string $contents): array
    {
        $text = $this->decode($contents);
        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
        $lines = array_values(array_filter($lines, fn ($l) => trim($l) !== ''));
        if ($lines === []) {
            throw new StatementFormatError('выписка пуста — ОТКАЗ (H4645/H5480)');
        }

        $delimiter = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
        $header = array_map('trim', str_getcsv(array_shift($lines), $delimiter));
        $shape = $this->shape($header);

        $rows = [];
        $skipped = 0;
        foreach ($lines as $line) {
            $rec = array_combine($header, array_pad(array_map('trim', str_getcsv($line, $delimiter)), count($header), ''));
            if ($rec === false) {
                $skipped++;

                continue;
            }
            $row = $shape === self::SHAPE_ACCOUNT ? $this->accountRow($rec) : $this->canonicalRow($rec);
            if ($row === null) {
                continue; // не зачисление — не наша строка
            }
            if ($row === false) {
                $skipped++; // зачисление, но дата/сумма не разобраны — громко считаем

                continue;
            }
            $rows[] = $row;
        }

        return ['rows' => $rows, 'skipped' => $skipped, 'shape' => $shape];
    }

    /** @param list<string> $header */
    private function shape(array $header): string
    {
        if (in_array('Направление', $header, true) && in_array('Назначение платежа', $header, true)) {
            return self::SHAPE_ACCOUNT;
        }
        if (array_map('mb_strtolower', $header) === self::CANONICAL_HEADER) {
            return self::SHAPE_CANONICAL;
        }

        throw new StatementFormatError(
            'неизвестный заголовок выписки (ожидался account с «Направление»+«Назначение платежа» '
            .'или canonical-credits '.implode(',', self::CANONICAL_HEADER).'); получен: '
            .implode(',', $header).' — ОТКАЗ разбирать наугад (H4645/H5480)'
        );
    }

    /**
     * @param  array<string, string>  $rec
     * @return array<string, mixed>|null|false null — не зачисление, false — не разобрано
     */
    private function accountRow(array $rec): array|null|false
    {
        if (! in_array($rec['Направление'] ?? '', self::INCOMING, true)) {
            return null;
        }
        $date = $this->date($rec['Дата зачисления'] ?? '') ?? $this->date($rec['Дата проводки'] ?? '');
        $kop = $this->kopecks($rec['Сумма операции в рублях'] ?? '') ?? $this->kopecks($rec['Сумма операции'] ?? '');
        if ($date === null || $kop === null) {
            return false;
        }

        return $this->row($date, $kop, $rec['Номер документа'] ?? null, $rec['Назначение платежа'] ?? null);
    }

    /**
     * @param  array<string, string>  $rec
     * @return array<string, mixed>|null|false
     */
    private function canonicalRow(array $rec): array|null|false
    {
        $lower = [];
        foreach ($rec as $k => $v) {
            $lower[mb_strtolower((string) $k)] = $v;
        }
        $currency = strtoupper(trim((string) ($lower['currency'] ?? 'RUB'))) ?: 'RUB';
        $date = $this->date($lower['date'] ?? '');
        $kop = $this->kopecks($lower['amount'] ?? '');
        if ($date === null || $kop === null) {
            return false;
        }
        if ($kop <= 0) {
            return null; // канонический срез несёт только зачисления; списание — не наша строка
        }

        return $this->row($date, $kop, null, $lower['description'] ?? null) + ['currency' => $currency];
    }

    /** @return array<string, mixed> */
    private function row(CarbonImmutable $date, int $kopecks, ?string $docNo, ?string $purpose): array
    {
        $purpose = $purpose !== null && trim($purpose) !== '' ? trim($purpose) : null;
        $kind = match (true) {
            $purpose === null => Credit::KIND_OTHER,
            preg_match(self::RE_CARD_AGGREGATE, $purpose) === 1 => Credit::KIND_CARD_AGGREGATE,
            preg_match(self::RE_QR_KIND, $purpose) === 1 => Credit::KIND_QR,
            preg_match(self::RE_ORDER, $purpose) === 1 => Credit::KIND_TRANSFER,
            default => Credit::KIND_OTHER,
        };

        return [
            'row_hash' => hash('sha256', ($docNo ?? '').'|'.$date->toDateString().'|'.Kopecks::toDecimal($kopecks).'|'.($purpose ?? '')),
            'value_date' => $date->toDateString(),
            'amount_kopecks' => $kopecks,
            'currency' => 'RUB',
            'kind' => $kind,
            'doc_no' => $docNo !== null && trim($docNo) !== '' ? trim($docNo) : null,
            'order_ref' => $purpose !== null && preg_match(self::RE_ORDER, $purpose, $m) === 1 ? (int) $m[1] : null,
            'qr_id' => $purpose !== null && preg_match(self::RE_QR, $purpose, $m) === 1 ? $m[1] : null,
            // Назначение сохраняем только у машинных строк банка (ПД в них нет).
            'purpose' => in_array($kind, Credit::MACHINE_KINDS, true) ? $purpose : null,
            'purpose_digest' => hash('sha256', (string) $purpose),
        ];
    }

    private function decode(string $raw): string
    {
        foreach (['UTF-8', 'Windows-1251'] as $enc) {
            if ($enc === 'UTF-8' && ! mb_check_encoding($raw, 'UTF-8')) {
                continue;
            }
            $text = $enc === 'UTF-8' ? $raw : mb_convert_encoding($raw, 'UTF-8', 'Windows-1251');

            return str_starts_with($text, "\u{FEFF}") ? substr($text, 3) : $text;
        }

        throw new StatementFormatError('файл не читается ни как UTF-8, ни как CP1251 — ОТКАЗ гадать (H4645)');
    }

    private function date(string $raw): ?CarbonImmutable
    {
        $raw = trim($raw);
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})/', $raw, $m) === 1) {
            return CarbonImmutable::createFromFormat('!Y-m-d', $m[3].'-'.$m[2].'-'.$m[1]) ?: null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $raw, $m) === 1) {
            return CarbonImmutable::createFromFormat('!Y-m-d', $m[0]) ?: null;
        }

        return null;
    }

    /** `1 234,56` / `1 234,56` / `1234.56` → копейки; null если не разобрано. */
    private function kopecks(string $raw): ?int
    {
        $s = str_replace(["\u{00A0}", ' ', "\u{202F}"], '', trim($raw));
        if ($s === '') {
            return null;
        }
        if (str_contains($s, '.') && str_contains($s, ',')) {
            $s = str_replace(['.', ','], ['', '.'], $s);
        } elseif (str_contains($s, ',')) {
            $s = str_replace(',', '.', $s);
        }
        if (preg_match('/^-?\d+(\.\d{1,2})?$/', $s) !== 1) {
            return null;
        }

        return Kopecks::fromDecimal($s);
    }
}
