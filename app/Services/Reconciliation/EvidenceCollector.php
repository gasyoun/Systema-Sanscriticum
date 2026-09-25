<?php

declare(strict_types=1);

namespace App\Services\Reconciliation;

use App\Models\Payment;
use App\Services\BlockAccessMaterializer;
use App\Services\Ledger\LedgerProjection;
use App\Services\Ledger\LedgerService;
use App\Support\Kopecks;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * H5445 (P3): собирает строки доказательств окна и ФАКТЫ, от которых зависит
 * их классификация (повтор доказательства, сумма возвратов, группы курса,
 * оставшийся доступ…). Классификация — чистая функция этих фактов, поэтому
 * отпечаток входа = хэш фактов, и повтор с тем же входом обязан дать ту же
 * контрольную сумму (иначе — дрейф).
 *
 * Только чтение. Каждый источник возвращает статус: present | legacy | dark |
 * missing. Отсутствующий источник — громко «missing» с причиной, никогда не
 * пустой список и не ноль.
 */
final class EvidenceCollector
{
    /**
     * Метка легаси-импорта (ImportAcademyData), а не номер транзакции: на проде
     * одна и та же «Мульти-оплата (Блоки 1-4)» стоит у 1242 оплат 182 студентов.
     * Доказательством оплаты не является — в ключи не попадает.
     */
    public const IMPORT_PLACEHOLDER_TXN = '/^Мульти-оплата \\(Блоки \\d+-\\d+\\)$/u';

    public const CH_BANK = 'bank_acquiring';

    public const CH_PAYPAL = 'paypal';

    public const CH_MANUAL = 'manual';

    public const CH_TEACHER = 'teacher_direct';

    public const CH_REFUND = 'refund';

    public const CH_WEBHOOK = 'webhook_journal';

    public function __construct(
        private readonly LedgerProjection $projection,
        private readonly BankStatementControl $statement,
    ) {}

    /**
     * @return array{window: array{from: ?string, to: string}, sources: array<string, array<string, mixed>>, rows: list<array<string, mixed>>, excluded: array<string, int>, ledger: array<string, mixed>}
     */
    public function collect(?CarbonInterface $from, CarbonInterface $to): array
    {
        $sources = [];
        $rows = [];
        $excluded = ['zero_amount_access_only' => 0, 'outflow_out_of_scope' => 0, 'pending_without_exception' => 0];

        // 1. Платежи (Точка/касса, PayPal, ручные заявки, прямые преподавателю, возвраты).
        try {
            $paymentRows = $this->paymentRows($from, $to, $excluded);
            foreach ([self::CH_BANK, self::CH_PAYPAL, self::CH_MANUAL, self::CH_TEACHER, self::CH_REFUND] as $ch) {
                $sources[$ch] = ['status' => 'present'];
            }
            $rows = array_merge($rows, $paymentRows);
        } catch (Throwable $e) {
            foreach ([self::CH_BANK, self::CH_PAYPAL, self::CH_MANUAL, self::CH_TEACHER, self::CH_REFUND] as $ch) {
                $sources[$ch] = ['status' => 'missing', 'note' => 'payments unreadable: '.$e->getMessage()];
            }
        }

        // 2. Журнал вебхуков банка/PayPal (что сообщил сам провайдер).
        if (Schema::hasTable('payment_webhook_events')) {
            $rows = array_merge($rows, $this->webhookRows($from, $to));
            $sources[self::CH_WEBHOOK] = ['status' => 'present'];
        } else {
            $sources[self::CH_WEBHOOK] = ['status' => 'missing', 'note' => 'payment_webhook_events table absent'];
        }

        // 3. Банковская выписка зачислений (H5480). Источник «present» только
        //    если импортированная выписка покрывает день ЦЕЛИКОМ; частичная —
        //    по-прежнему missing, и прогон честно incomplete. Не ноль.
        $sources['bank_statement'] = $this->bankStatementSource($from, $to);

        // 4. Ядро P1.
        $ledger = $this->ledgerSnapshot();
        $sources['ledger'] = ['status' => $ledger['status'], 'note' => $ledger['note'] ?? null];

        // 5. Пакеты выплат (P2/H5444) — пока легаси teacher_payouts.
        $sources['payout_packages'] = $this->payoutPackages();

        return [
            'window' => ['from' => $from?->toIso8601String(), 'to' => $to->toIso8601String()],
            'sources' => $sources,
            'rows' => $rows,
            'excluded' => $excluded,
            'ledger' => $ledger,
        ];
    }

    /**
     * @param  array<string, int>  $excluded
     * @return list<array<string, mixed>>
     */
    private function paymentRows(?CarbonInterface $from, CarbonInterface $to, array &$excluded): array
    {
        $dateExpr = 'COALESCE(first_paid_at, created_at)';
        $q = Payment::query()
            ->where(function ($w): void {
                $w->whereIn('status', Payment::PAID_STATUSES)
                    ->orWhere(fn ($p) => $p->where('status', 'pending')->where('claim_meta', 'like', '%reconciliation_exception%'));
            })
            ->whereRaw("{$dateExpr} < ?", [$to->toDateTimeString()])
            ->when($from !== null, fn ($q) => $q->whereRaw("{$dateExpr} >= ?", [$from->toDateTimeString()]))
            ->orderBy('id');

        $candidates = [];
        foreach ($q->lazyById(500) as $p) {
            /** @var Payment $p */
            $kop = Kopecks::fromDecimal((string) $p->amount);
            $isRefund = $p->refund_of_payment_id !== null;
            if (! $isRefund && in_array($p->tariff, ['Расход', 'salary_payout'], true)) {
                $excluded['outflow_out_of_scope']++;

                continue;
            }
            if (! $isRefund && $kop === 0) {
                $excluded['zero_amount_access_only']++;

                continue;
            }
            $candidates[] = $p;
        }

        if ($candidates === []) {
            return [];
        }

        $reuse = $this->evidenceKeyCounts($candidates);
        $groupless = $this->courseIdsWithoutGroups();
        $promoRank = $this->promoRedemptionRanks($candidates);
        $promoTerms = $this->promoTerms($candidates);
        $families = $this->refundFamilies($candidates);

        $rows = [];
        foreach ($candidates as $p) {
            $rows[] = $this->paymentFacts($p, $reuse, $groupless, $promoRank, $promoTerms, $families);
        }

        return $rows;
    }

    /**
     * Условия промокодов одним запросом (вместо запроса на строку — окно --all).
     *
     * @param  list<Payment>  $candidates
     * @return array<int, object>
     */
    private function promoTerms(array $candidates): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn (Payment $p) => $p->promo_code_id !== null ? (int) $p->promo_code_id : null, $candidates))));
        $out = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach (DB::table('promo_codes')->whereIn('id', $chunk)->get(['id', 'expires_at', 'usage_limit']) as $row) {
                $out[(int) $row->id] = $row;
            }
        }

        return $out;
    }

    /**
     * Исходные оплаты возвратов и суммы их оплаченных возвратов — двумя
     * запросами на всё окно.
     *
     * @param  list<Payment>  $candidates
     * @return array<int, array{original: ?Payment, refunded: int}>
     */
    private function refundFamilies(array $candidates): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn (Payment $p) => $p->refund_of_payment_id !== null ? (int) $p->refund_of_payment_id : null, $candidates))));
        $out = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $originals = Payment::query()->whereKey($chunk)->get()->keyBy('id');
            foreach ($chunk as $id) {
                $out[$id] = ['original' => $originals->get($id), 'refunded' => 0];
            }
            Payment::query()->whereIn('refund_of_payment_id', $chunk)->paid()->get(['refund_of_payment_id', 'amount'])
                ->each(function (Payment $r) use (&$out): void {
                    $out[(int) $r->refund_of_payment_id]['refunded'] += abs(Kopecks::fromDecimal((string) $r->amount));
                });
        }

        return $out;
    }

    /**
     * @param  array<string, int>  $reuse
     * @param  array<int, true>  $groupless
     * @param  array<int, int>  $promoRank
     * @param  array<int, object>  $promoTerms
     * @param  array<int, array{original: ?Payment, refunded: int}>  $families
     * @return array<string, mixed>
     */
    private function paymentFacts(Payment $p, array $reuse, array $groupless, array $promoRank, array $promoTerms, array $families): array
    {
        $meta = is_array($p->claim_meta) ? $p->claim_meta : [];
        $kop = Kopecks::fromDecimal((string) $p->amount);
        $paidAt = $p->first_paid_at ?? $p->created_at;
        $isRefund = $p->refund_of_payment_id !== null;

        $channel = match (true) {
            $isRefund => self::CH_REFUND,
            $p->received_account === Payment::RECEIVED_TEACHER || $p->provider === Payment::PROVIDER_TEACHER_TRANSFER => self::CH_TEACHER,
            in_array($p->provider, [Payment::PROVIDER_PAYPAL, Payment::PROVIDER_PAYPAL_SUBSCRIPTION], true) => self::CH_PAYPAL,
            in_array($p->provider, [Payment::PROVIDER_INVOICE, Payment::PROVIDER_BANK_SEPA], true) => self::CH_MANUAL,
            default => self::CH_BANK,
        };

        $keys = self::evidenceKeys($p);
        $maxReuse = 1;
        foreach ($keys as $k) {
            $maxReuse = max($maxReuse, $reuse[$k] ?? 1);
        }

        $facts = [
            'ref' => 'payment:'.$p->id,
            'source' => $channel,
            'payment_id' => (int) $p->id,
            'status' => (string) $p->status,
            'provider' => $p->provider,
            'tariff' => $p->tariff,
            'user_id' => $p->user_id !== null ? (int) $p->user_id : null,
            'course_id' => $p->course_id !== null ? (int) $p->course_id : null,
            'amount_kopecks' => $kop,
            'currency' => 'RUB',
            'foreign_currency' => $p->foreign_currency,
            'foreign_minor' => $p->foreign_amount !== null ? Kopecks::fromDecimal((string) $p->foreign_amount) : null,
            'start_block' => $p->start_block !== null ? (int) $p->start_block : null,
            'end_block' => $p->end_block !== null ? (int) $p->end_block : null,
            'paid_at' => $paidAt?->toDateTimeString(),
            'evidence_keys' => $keys,
            'evidence_reuse' => $maxReuse,
            'claim_exception' => $meta['reconciliation_exception'] ?? null,
            'amount_verdict' => $meta['amount_check']['verdict'] ?? null,
            'link_expires_at' => $p->payment_link_expires_at?->toDateTimeString(),
            'promo_code_id' => $p->promo_code_id !== null ? (int) $p->promo_code_id : null,
            'promo_expires_at' => null,
            'promo_usage_limit' => null,
            'promo_rank' => $promoRank[(int) $p->id] ?? null,
            'course_has_groups' => $p->course_id === null ? null : ! isset($groupless[(int) $p->course_id]),
        ];

        if ($p->promo_code_id !== null) {
            $promo = $promoTerms[(int) $p->promo_code_id] ?? null;
            $facts['promo_expires_at'] = $promo?->expires_at !== null ? (string) $promo->expires_at : null;
            $facts['promo_usage_limit'] = $promo?->usage_limit !== null ? (int) $promo->usage_limit : null;
        }

        if ($isRefund) {
            $facts += $this->refundFacts($p, $families[(int) $p->refund_of_payment_id] ?? ['original' => null, 'refunded' => 0]);
        }

        return $facts;
    }

    /**
     * @param  array{original: ?Payment, refunded: int}  $family
     * @return array<string, mixed>
     */
    private function refundFacts(Payment $refund, array $family): array
    {
        $original = $family['original'];
        if ($original === null) {
            return ['refund_of' => (int) $refund->refund_of_payment_id, 'original_missing' => true];
        }
        $origKop = Kopecks::fromDecimal((string) $original->amount);
        $refunded = $family['refunded'];
        $full = $origKop > 0 && $refunded >= $origKop;
        [$from, $to] = RefundAccessPolicy::blockRange($original);

        return [
            'refund_of' => (int) $original->id,
            'original_missing' => false,
            'original_kopecks' => $origKop,
            'family_refunded_kopecks' => $refunded,
            'refund_is_full' => $full,
            'original_block_from' => $from,
            'original_block_to' => $to,
            // Полный возврат, а доступ к группам курса у студента остался при
            // отсутствии другой дающей доступ оплаты — утечка доступа (D10).
            'access_retained_after_full_refund' => $full && $this->accessRetained($original),
        ];
    }

    private function accessRetained(Payment $original): bool
    {
        if ($original->user_id === null || $original->course_id === null
            || in_array($original->tariff, ['deposit', 'trial', 'Расход', 'salary_payout'], true)) {
            return false;
        }
        $groupIds = DB::table('course_group')->where('course_id', $original->course_id)->pluck('group_id')->all();
        if ($groupIds === []) {
            return false;
        }
        // Путь доступа (User::groups) учитывает и «вышедших» (left_at) — считаем так же.
        $inGroups = DB::table('group_user')->where('user_id', $original->user_id)->whereIn('group_id', $groupIds)->exists();
        if (! $inGroups) {
            return false;
        }

        // Есть ли другая оплата курса, которая по-честному даёт доступ (не возвращённая целиком).
        $paid = "'".implode("','", Payment::PAID_STATUSES)."'";

        return ! Payment::query()
            ->where('user_id', $original->user_id)
            ->where('course_id', $original->course_id)
            ->whereKeyNot($original->id)
            ->paid()
            ->where('amount', '>', 0)
            ->whereNull('refund_of_payment_id')
            ->whereNotIn('tariff', ['deposit', 'trial', 'Расход', 'salary_payout'])
            ->whereRaw("payments.amount + COALESCE((SELECT SUM(r.amount) FROM payments r WHERE r.refund_of_payment_id = payments.id AND r.status IN ({$paid})), 0) > 0")
            ->exists();
    }

    /**
     * Ключи доказательства оплаты: номер транзакции, PayPal txn, реквизит перевода.
     *
     * @return list<string>
     */
    public static function evidenceKeys(Payment $p): array
    {
        $keys = [];
        $txn = is_string($p->transaction_id) ? trim($p->transaction_id) : '';
        if ($txn !== '' && ! str_starts_with($txn, BlockAccessMaterializer::GRANT_PREFIX)
            && preg_match(self::IMPORT_PLACEHOLDER_TXN, $txn) !== 1) {
            $keys[] = 'txn:'.$txn;
        }
        $meta = is_array($p->claim_meta) ? $p->claim_meta : [];
        foreach (['txn' => 'claim-txn', 'reference' => 'claim-ref'] as $field => $prefix) {
            $v = isset($meta[$field]) && is_scalar($meta[$field]) ? trim((string) $meta[$field]) : '';
            if ($v !== '') {
                $keys[] = $prefix.':'.$p->provider.':'.mb_strtolower($v);
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Сколько раз каждый ключ доказательства использован повторно среди ВСЕХ
     * оплаченных поступлений (не только окна): повтор со старой строкой тоже повтор.
     *
     * Одна оплата, разложенная на блоки одного студента одного курса (разные
     * тарифы, один номер транзакции), — это один платёж, не повтор: ключ такой
     * семьи считается один раз. Повтор — это ключ у разных студентов или курсов,
     * либо дважды за один и тот же тариф (D16).
     *
     * @param  list<Payment>  $candidates
     * @return array<string, int>
     */
    private function evidenceKeyCounts(array $candidates): array
    {
        $wanted = [];
        foreach ($candidates as $p) {
            if ($p->refund_of_payment_id === null) {
                foreach (self::evidenceKeys($p) as $k) {
                    $wanted[$k] = [];
                }
            }
        }
        if ($wanted === []) {
            return [];
        }

        Payment::query()
            ->whereIn('status', Payment::PAID_STATUSES)
            ->whereNull('refund_of_payment_id')
            ->where('amount', '>', 0)
            ->whereNotIn('tariff', ['Расход', 'salary_payout'])
            ->select(['id', 'user_id', 'course_id', 'tariff', 'transaction_id', 'provider', 'claim_meta'])
            ->lazyById(1000)
            ->each(function (Payment $p) use (&$wanted): void {
                foreach (self::evidenceKeys($p) as $k) {
                    if (isset($wanted[$k])) {
                        $wanted[$k][] = $p->user_id.'|'.$p->course_id.'|'.$p->tariff;
                    }
                }
            });

        $out = [];
        foreach ($wanted as $k => $uses) {
            $owners = array_unique(array_map(fn (string $u) => substr($u, 0, (int) strrpos($u, '|')), $uses));
            $splitOfOnePayment = count($owners) === 1 && count(array_unique($uses)) === count($uses);
            $out[$k] = $splitOfOnePayment ? 1 : count($uses);
        }

        return $out;
    }

    /** @return array<int, true> */
    private function courseIdsWithoutGroups(): array
    {
        return DB::table('courses')
            ->whereNotExists(fn ($q) => $q->from('course_group')->whereColumn('course_group.course_id', 'courses.id'))
            ->pluck('id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /**
     * Порядковый номер оплаты среди оплаченных погашений промокода (по id) —
     * чтобы увидеть оплату сверх лимита промокода.
     *
     * @param  list<Payment>  $candidates
     * @return array<int, int>
     */
    private function promoRedemptionRanks(array $candidates): array
    {
        $wanted = [];
        foreach ($candidates as $p) {
            if ($p->promo_code_id !== null && $p->refund_of_payment_id === null) {
                $wanted[(int) $p->id] = true;
            }
        }
        $promoIds = array_values(array_unique(array_map(fn (Payment $p) => (int) $p->promo_code_id, array_filter($candidates, fn (Payment $p) => isset($wanted[(int) $p->id])))));

        // Один проход по погашениям каждого промокода в порядке id вместо COUNT на строку.
        $ranks = [];
        $seen = [];
        foreach (array_chunk($promoIds, 500) as $chunk) {
            Payment::query()
                ->whereIn('promo_code_id', $chunk)
                ->whereIn('status', Payment::PAID_STATUSES)
                ->whereNull('refund_of_payment_id')
                ->orderBy('id')
                ->toBase()
                ->get(['id', 'promo_code_id'])
                ->each(function ($r) use (&$ranks, &$seen, $wanted): void {
                    $n = $seen[(int) $r->promo_code_id] = ($seen[(int) $r->promo_code_id] ?? 0) + 1;
                    if (isset($wanted[(int) $r->id])) {
                        $ranks[(int) $r->id] = $n;
                    }
                });
        }

        return $ranks;
    }

    /** @return list<array<string, mixed>> */
    private function webhookRows(?CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = [];
        DB::table('payment_webhook_events')
            ->where('created_at', '<', $to->toDateTimeString())
            ->when($from !== null, fn ($q) => $q->where('created_at', '>=', $from->toDateTimeString()))
            ->orderBy('id')
            ->each(function ($e) use (&$rows): void {
                $rows[] = [
                    'ref' => 'webhook:'.$e->id,
                    'source' => self::CH_WEBHOOK,
                    'webhook_id' => (int) $e->id,
                    'provider' => $e->provider,
                    'decision' => $e->decision,
                    'bank_status' => $e->bank_status,
                    'payment_id' => $e->payment_id !== null ? (int) $e->payment_id : null,
                    'amount_kopecks' => $e->reported_amount !== null ? Kopecks::fromDecimal((string) $e->reported_amount) : null,
                    'currency' => 'RUB',
                    'user_id' => null,
                    'event_hash' => $e->event_hash,
                ];
            });

        return $rows;
    }

    /** @return array<string, mixed> */
    private function bankStatementSource(?CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->statement->source(
            $from !== null ? CarbonImmutable::parse($from) : null,
            CarbonImmutable::parse($to),
        );
    }

    /** @return array<string, mixed> */
    private function ledgerSnapshot(): array
    {
        if (! Schema::hasTable('money_movements')) {
            return ['status' => 'missing', 'note' => 'P1 ledger tables absent'];
        }
        $totals = $this->projection->controlTotals();
        $live = $totals['movements'] > 0 || app(LedgerService::class)->writable();

        return [
            'status' => $live ? 'present' : 'dark',
            'note' => $live ? null : 'P1 ledger is dark (MONEY_LEDGER_CORE off, no rows) — evidence cannot be matched against the subledger until P4 cutover',
            'totals' => $totals,
            'breaches' => $live ? $this->projection->integrityBreaches() : [],
            'residues' => $live ? $this->familyResidues() : [],
            'legacy_links' => $live ? $this->legacyLinks() : [],
        ];
    }

    /** @return array<int, int> anchor movement id => нераспределённый остаток семьи (≠ 0) */
    private function familyResidues(): array
    {
        $out = [];
        DB::table('money_movements')->whereNull('root_movement_id')->whereIn('type', ['receipt', 'direct_teacher_receipt'])
            ->orderBy('id')->pluck('id')
            ->each(function ($id) use (&$out): void {
                $r = $this->projection->familyResidue((int) $id);
                if ($r !== 0) {
                    $out[(int) $id] = $r;
                }
            });

        return $out;
    }

    /** @return array<int, int> legacy payment id => нетто ядра по его корневым поступлениям */
    private function legacyLinks(): array
    {
        $out = [];
        DB::table('money_movements')->whereNotNull('legacy_payment_id')->whereNull('root_movement_id')
            ->whereIn('type', ['receipt', 'direct_teacher_receipt'])->orderBy('id')->get(['id', 'legacy_payment_id'])
            ->each(function ($m) use (&$out): void {
                $out[(int) $m->legacy_payment_id] = ($out[(int) $m->legacy_payment_id] ?? 0) + $this->projection->chainNet((int) $m->id);
            });

        return $out;
    }

    /** @return array<string, mixed> */
    private function payoutPackages(): array
    {
        $table = (string) config('money_recon.payout_packages_table', 'teacher_payout_packages');
        if (Schema::hasTable($table)) {
            return ['status' => 'present', 'table' => $table, 'rows' => DB::table($table)->count()];
        }
        if (! Schema::hasTable('teacher_payouts')) {
            return ['status' => 'missing', 'note' => "neither {$table} (P2/H5444) nor teacher_payouts exists"];
        }
        $byCurrency = [];
        $count = 0;
        $withKey = 0;
        $hasKey = Schema::hasColumn('teacher_payouts', 'settlement_key');
        DB::table('teacher_payouts')->orderBy('id')->each(function ($r) use (&$byCurrency, &$count, &$withKey, $hasKey): void {
            $count++;
            $byCurrency['RUB'] = ($byCurrency['RUB'] ?? 0) + Kopecks::fromDecimal((string) $r->amount);
            if ($hasKey && ! empty($r->settlement_key)) {
                $withKey++;
            }
        });

        return [
            'status' => 'legacy',
            'note' => "P2 package table {$table} (H5444) not deployed — totals from legacy teacher_payouts",
            'rows' => $count,
            'kopecks' => $byCurrency,
            'with_settlement_key' => $withKey,
        ];
    }
}
