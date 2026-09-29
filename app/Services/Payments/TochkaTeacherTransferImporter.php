<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\TeacherPayoutIdentity;
use App\Models\TeacherTransferMatch;
use App\Models\TochkaOutgoingTransfer;
use App\Support\Kopecks;
use Illuminate\Support\Facades\DB;

/** Imports evidence only. It never creates Payment or TeacherPayout rows. */
final class TochkaTeacherTransferImporter
{
    /** @return array{outcome:string,total:int,imported:int,duplicate:int,conflict:int,matched:int,unmatched:int,skipped:int} */
    public function importPayload(array $payload, bool $apply = false): array
    {
        $rows = $this->transactions($payload);
        $result = ['outcome' => $apply ? 'imported' : 'dry_run', 'total' => 0, 'imported' => 0, 'duplicate' => 0, 'conflict' => 0, 'matched' => 0, 'unmatched' => 0, 'skipped' => 0];

        foreach ($rows as $envelope) {
            $tx = $envelope['transaction'];
            if (($tx['creditDebitIndicator'] ?? null) !== 'Debit' || ($tx['status'] ?? null) !== 'Booked') {
                continue;
            }
            $result['total']++;
            $row = $this->normalize($tx, (string) $envelope['statement_id'], (string) $envelope['account_tail']);
            if ($row === null) {
                $result['skipped']++;

                continue;
            }

            $existing = TochkaOutgoingTransfer::query()->where('provider_transaction_id', $row['provider_transaction_id'])->first();
            if ($existing !== null) {
                if (! hash_equals((string) $existing->payload_fingerprint, $row['payload_fingerprint'])) {
                    $result['conflict']++;
                } else {
                    $result['duplicate']++;
                }

                continue;
            }

            $identity = $this->uniqueIdentity($row, $row['booked_on']);
            if ($identity === null) {
                $result['unmatched']++;
            } else {
                $result['matched']++;
            }
            if (! $apply) {
                $result['imported']++;

                continue;
            }

            DB::transaction(function () use ($row, $identity): void {
                $transfer = TochkaOutgoingTransfer::query()->create($row + ['imported_at' => now()]);
                if ($identity !== null) {
                    TeacherTransferMatch::query()->create([
                        'transfer_id' => $transfer->id,
                        'teacher_id' => $identity->teacher_id,
                        'identity_id' => $identity->id,
                        'match_basis' => $identity->identity_type.'_hmac',
                    ]);
                }
            });
            $result['imported']++;
        }

        if ($result['conflict'] > 0 || $result['skipped'] > 0) {
            $result['outcome'] = 'incomplete';
        }

        return $result;
    }

    /** @return list<array{transaction:array<string,mixed>,statement_id:string,account_tail:string}> */
    private function transactions(array $payload): array
    {
        $statements = isset($payload['statements']) && is_array($payload['statements']) ? $payload['statements'] : [['data' => $payload]];
        $out = [];
        foreach ($statements as $wrapper) {
            if (! is_array($wrapper)) {
                continue;
            }
            $data = is_array($wrapper['data'] ?? null) ? $wrapper['data'] : $wrapper;
            $statement = data_get($data, 'Data.Statement', []);
            $statementRows = array_is_list($statement) ? $statement : [$statement];
            foreach ($statementRows as $statementRow) {
                if (! is_array($statementRow)) {
                    continue;
                }
                $statementId = (string) ($statementRow['statementId'] ?? $wrapper['statement_id'] ?? '');
                $account = (string) ($statementRow['accountId'] ?? '');
                $digits = preg_replace('/\D+/', '', explode('/', $account, 2)[0]) ?? '';
                foreach ((array) ($statementRow['Transaction'] ?? []) as $tx) {
                    if (is_array($tx)) {
                        $out[] = ['transaction' => $tx, 'statement_id' => $statementId, 'account_tail' => $digits === '' ? '' : substr($digits, -6)];
                    }
                }
            }
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    private function normalize(array $tx, string $statementId, string $accountTail): ?array
    {
        $transactionId = trim((string) ($tx['transactionId'] ?? ''));
        $date = substr((string) ($tx['documentProcessDate'] ?? ''), 0, 10);
        $amount = data_get($tx, 'Amount.amount');
        $currency = strtoupper((string) data_get($tx, 'Amount.currency', ''));
        if ($transactionId === '' || $statementId === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || ! is_numeric($amount) || strlen($currency) !== 3) {
            return null;
        }
        $inn = trim((string) data_get($tx, 'CreditorParty.inn', ''));
        $account = trim((string) data_get($tx, 'CreditorAccount.identification', ''));
        $purpose = (string) ($tx['description'] ?? '');
        $selected = [
            'transaction_id' => $transactionId,
            'payment_id' => (string) ($tx['paymentId'] ?? ''),
            'statement_id' => $statementId,
            'date' => $date,
            'amount' => (string) $amount,
            'currency' => $currency,
            'document_no' => (string) ($tx['documentNumber'] ?? ''),
            'inn_hmac' => $inn === '' ? null : TeacherPaymentIdentity::digest($inn),
            'account_hmac' => $account === '' ? null : TeacherPaymentIdentity::digest($account),
            'purpose_digest' => hash('sha256', $purpose),
        ];

        return [
            'provider_transaction_id' => $transactionId,
            'provider_payment_id' => $selected['payment_id'] !== '' ? $selected['payment_id'] : null,
            'statement_id' => $statementId,
            'account_tail' => $accountTail !== '' ? $accountTail : null,
            'booked_on' => $date,
            'amount_kopecks' => Kopecks::fromDecimal((string) $amount),
            'currency' => $currency,
            'document_no' => $selected['document_no'] !== '' ? mb_substr($selected['document_no'], 0, 64) : null,
            'recipient_inn_hmac' => $selected['inn_hmac'],
            'recipient_account_hmac' => $selected['account_hmac'],
            'purpose_digest' => $selected['purpose_digest'],
            'payload_fingerprint' => hash('sha256', json_encode($selected, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        ];
    }

    private function uniqueIdentity(array $row, string $bookedOn): ?TeacherPayoutIdentity
    {
        $digests = array_filter([
            'inn' => $row['recipient_inn_hmac'],
            'account' => $row['recipient_account_hmac'],
        ]);
        if ($digests === []) {
            return null;
        }

        $matches = TeacherPayoutIdentity::query()
            ->where('provider', 'tochka')
            ->where(function ($q) use ($digests): void {
                foreach ($digests as $type => $digest) {
                    $q->orWhere(fn ($sub) => $sub->where('identity_type', $type)->where('identity_hmac', $digest));
                }
            })
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $bookedOn))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $bookedOn))
            ->get();
        $teacherIds = $matches->pluck('teacher_id')->unique();

        return $teacherIds->count() === 1 ? $matches->firstWhere('teacher_id', $teacherIds->first()) : null;
    }
}
