<?php

declare(strict_types=1);

namespace App\Services\Reconciliation;

use App\Models\BankStatementCredit;
use App\Support\Kopecks;

/**
 * H5480: разбор CSV банковской выписки Точки в строки ЗАЧИСЛЕНИЙ.
 *
 * Порт прежнего искусства, а не новый разбор: формы заголовка, кодировки,
 * формат сумм/дат и формула row_hash повторяют сенсор H4645
 * (Uprava tools/tochka_credits_paid_no_access_sensor.py) — один хэш строки на
 * два потребителя, поэтому сенсор и импорт говорят об одной и той же строке.
 *
 * Неизвестный заголовок — ОТКАЗ (StatementFormatError), никогда молчаливый
 * пропуск. Строку с неразборчивой датой/суммой считаем в skipped и называем
 * вслух.
 *
 * Назначение платежа наружу не отдаётся: только sha256 и технические токены
 * (QR ID, «Заказ №N»).
 */
final class BankStatementParser
{
    /** Заголовок формы «account» — выгрузка счёта как есть. */
    private const ACCOUNT_MARKERS = ['Направление', 'Назначение платежа'];

    /** Каноническая форма (та же, что принимает сенсор H4645). */
    private const CANONICAL_HEADER = ['date', 'amount', 'currency', 'payer', 'description'];

    private const INCOMING = ['Входящий', 'Зачисление'];

    private const RE_ORDER_REF = '/Заказ\s*№\s*(\d+)/u';

    private const RE_QR_ID = '/QR коду ID\s+([A-Za-z0-9]+)/u';

    /** Эквайринг карт приходит ДНЕВНЫМ агрегатом за вычетом комиссии (H4645). */
    private const RE_ACQUIRING = '/по договору об обслуживании держателей платежных карт|эквайринг/iu';

    /**
     * @return array{rows: list<array<string, mixed>>, skipped: int, total: int}
     *
     * @throws StatementFormatError
     */
    public function parseFile(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new StatementFormatError("statement: {$path} is not a readable file");
        }

        return $this->parse((string) file_get_contents($path), $path);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, skipped: int, total: int}
     *
     * @throws StatementFormatError
     */
    public function parse(string $data, string $label = 'statement'): array
    {
        $text = $this->decode($data, $label);
        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
        $lines = array_values(array_filter($lines, fn ($l) => trim($l) !== ''));
        if ($lines === []) {
            throw new StatementFormatError("{$label}: empty file — REFUSING (H4645)");
        }

        $delimiter = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
        $header = array_map('trim', str_getcsv(array_shift($lines), $delimiter));

        $shape = match (true) {
            count(array_intersect(self::ACCOUNT_MARKERS, $header)) === count(self::ACCOUNT_MARKERS) => 'account',
            array_map('strtolower', $header) === self::CANONICAL_HEADER => 'canonical',
            default => throw new StatementFormatError(
                "{$label}: unknown statement header (expected SHAPE account with "
                .implode('+', self::ACCOUNT_MARKERS).', or SHAPE canonical-credits '
                .implode(',', self::CANONICAL_HEADER).'); header seen: '.implode(',', $header)
                .' — REFUSING to silently skip (H4645)'
            ),
        };

        $rows = [];
        $skipped = 0;
        $total = 0;
        foreach ($lines as $line) {
            $raw = str_getcsv($line, $delimiter);
            $rec = [];
            foreach ($header as $i => $name) {
                $rec[$name] = isset($raw[$i]) ? trim((string) $raw[$i]) : '';
            }

            $parsed = $shape === 'account' ? $this->accountRow($rec) : $this->canonicalRow($rec);
            if ($parsed === 'not_incoming') {
                continue;
            }
            $total++;
            if ($parsed === null) {
                $skipped++;

                continue;
            }
            $rows[] = $parsed;
        }

        return ['rows' => $rows, 'skipped' => $skipped, 'total' => $total];
    }

    /** @return array<string, mixed>|string|null */
    private function accountRow(array $rec): array|string|null
    {
        if (! in_array($rec['Направление'] ?? '', self::INCOMING, true)) {
            return 'not_incoming';
        }
        $date = $this->parseRuDate($rec['Дата зачисления'] ?? '') ?? $this->parseRuDate($rec['Дата проводки'] ?? '');
        $amount = $this->parseAmount($rec['Сумма операции в рублях'] ?? '') ?? $this->parseAmount($rec['Сумма операции'] ?? '');
        if ($date === null || $amount === null) {
            return null;
        }

        return $this->makeRow($date, $amount, $rec['Номер документа'] ?? '', $rec['Назначение платежа'] ?? '');
    }

    /** @return array<string, mixed>|null */
    private function canonicalRow(array $rec): ?array
    {
        $date = substr((string) ($rec['date'] ?? ''), 0, 10);
        $amount = $this->parseAmount($rec['amount'] ?? '');
        if ($amount === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        return $this->makeRow($date, $amount, '', $rec['description'] ?? '');
    }

    /**
     * row_hash = sha256("документ|дата|сумма с 2 знаками|назначение") — формула
     * сенсора H4645, менять её нельзя: она связывает два инструмента.
     *
     * @return array<string, mixed>
     */
    private function makeRow(string $date, string $amount, string $docNo, string $purpose): array
    {
        $kopecks = Kopecks::fromDecimal($amount);
        $decimal = number_format($kopecks / 100, 2, '.', '');

        $qrId = preg_match(self::RE_QR_ID, $purpose, $m) === 1 ? $m[1] : null;
        $orderRef = preg_match(self::RE_ORDER_REF, $purpose, $m) === 1 ? (int) $m[1] : null;

        $kind = match (true) {
            $qrId !== null => BankStatementCredit::KIND_QR,
            preg_match(self::RE_ACQUIRING, $purpose) === 1 => BankStatementCredit::KIND_ACQUIRING,
            $orderRef !== null => BankStatementCredit::KIND_TRANSFER,
            default => BankStatementCredit::KIND_OTHER,
        };

        return [
            'row_hash' => hash('sha256', $docNo.'|'.$date.'|'.$decimal.'|'.$purpose),
            'booked_on' => $date,
            'amount_kopecks' => $kopecks,
            'currency' => 'RUB',
            'kind' => $kind,
            'doc_no' => $docNo !== '' ? mb_substr($docNo, 0, 64) : null,
            'qr_id' => $qrId !== null ? mb_substr($qrId, 0, 64) : null,
            'order_ref' => $orderRef,
            'purpose_digest' => hash('sha256', $purpose),
        ];
    }

    private function decode(string $data, string $label): string
    {
        if (str_starts_with($data, "\xEF\xBB\xBF")) {
            return substr($data, 3);
        }
        if (mb_check_encoding($data, 'UTF-8')) {
            return $data;
        }
        $cp1251 = @mb_convert_encoding($data, 'UTF-8', 'CP1251');
        if ($cp1251 === false || ! mb_check_encoding($cp1251, 'UTF-8')) {
            throw new StatementFormatError("{$label}: cannot decode as utf-8 or cp1251 — REFUSING to guess (H4645)");
        }

        return $cp1251;
    }

    /** `1 234,56` / `1 234,56` / `1234.56` → `1234.56`; null если не число. */
    private function parseAmount(string $raw): ?string
    {
        $s = str_replace(["\u{00a0}", ' ', "\t"], '', trim($raw));
        if ($s === '') {
            return null;
        }
        if (str_contains($s, '.') && str_contains($s, ',')) {
            // 1.234,56 — точка разделяет тысячи, запятая десятичная.
            $s = str_replace(',', '.', str_replace('.', '', $s));
        } elseif (str_contains($s, ',')) {
            $s = str_replace(',', '.', $s);
        }

        // Только рубли с точностью до копейки — больше двух знаков Kopecks не примет.
        return preg_match('/^-?\d+(\.\d{1,2})?$/', $s) === 1 ? $s : null;
    }

    /** DD.MM.YYYY → YYYY-MM-DD; null если не разобрать. */
    private function parseRuDate(string $raw): ?string
    {
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})/', trim($raw), $m) !== 1) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? "{$m[3]}-{$m[2]}-{$m[1]}" : null;
    }
}
