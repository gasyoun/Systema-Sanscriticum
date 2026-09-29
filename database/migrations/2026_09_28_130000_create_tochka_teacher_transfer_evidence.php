<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_payout_identities', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $t->string('provider', 32)->default('tochka');
            $t->string('identity_type', 16);
            $t->char('identity_hmac', 64);
            $t->string('display_tail', 16)->nullable();
            $t->date('valid_from')->nullable();
            $t->date('valid_to')->nullable();
            $t->unsignedBigInteger('confirmed_by')->nullable();
            $t->timestamp('confirmed_at');
            $t->unique(['provider', 'identity_type', 'identity_hmac'], 'teacher_identity_exact_unique');
        });

        Schema::create('tochka_outgoing_transfers', function (Blueprint $t): void {
            $t->id();
            $t->string('provider_transaction_id', 191)->unique();
            $t->string('provider_payment_id', 191)->nullable()->index();
            $t->string('statement_id', 191);
            $t->string('account_tail', 16)->nullable();
            $t->date('booked_on')->index();
            $t->bigInteger('amount_kopecks');
            $t->char('currency', 3);
            $t->string('document_no', 64)->nullable();
            $t->char('recipient_inn_hmac', 64)->nullable()->index();
            $t->char('recipient_account_hmac', 64)->nullable()->index();
            $t->char('purpose_digest', 64);
            $t->char('payload_fingerprint', 64);
            $t->timestamp('imported_at');
        });

        Schema::create('teacher_transfer_matches', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('transfer_id')->unique()->constrained('tochka_outgoing_transfers');
            $t->foreignId('teacher_id')->constrained();
            $t->foreignId('identity_id')->nullable()->constrained('teacher_payout_identities');
            $t->string('match_basis', 32);
            $t->unsignedBigInteger('confirmed_by')->nullable();
            $t->timestamp('created_at');
        });

        Schema::create('teacher_payout_evidence_links', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('transfer_id')->unique()->constrained('tochka_outgoing_transfers');
            $t->foreignId('teacher_payout_id')->unique()->constrained('teacher_payouts');
            $t->unsignedBigInteger('confirmed_by')->nullable();
            $t->timestamp('created_at');
        });

        $this->createTriggers();
    }

    public function down(): void
    {
        $this->dropTriggers();
        Schema::dropIfExists('teacher_payout_evidence_links');
        Schema::dropIfExists('teacher_transfer_matches');
        Schema::dropIfExists('tochka_outgoing_transfers');
        Schema::dropIfExists('teacher_payout_identities');
    }

    private function createTriggers(): void
    {
        $driver = DB::connection()->getDriverName();
        foreach ($this->tables() as $table) {
            foreach (['UPDATE' => 'immutable', 'DELETE' => 'never deleted'] as $event => $reason) {
                $name = substr('pay_ev_'.str_replace('_', '', $table).'_'.strtolower($event), 0, 60);
                $message = "payroll evidence: {$table} is {$reason}";
                if ($driver === 'sqlite') {
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW BEGIN SELECT RAISE(ABORT, '".str_replace("'", "''", $message)."'); END");
                } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".str_replace("'", "''", $message)."'; END");
                } else {
                    throw new RuntimeException("payroll evidence triggers: unsupported driver {$driver}");
                }
            }
        }
    }

    private function dropTriggers(): void
    {
        foreach ($this->tables() as $table) {
            foreach (['UPDATE', 'DELETE'] as $event) {
                $name = substr('pay_ev_'.str_replace('_', '', $table).'_'.strtolower($event), 0, 60);
                DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
            }
        }
    }

    /** @return list<string> */
    private function tables(): array
    {
        return ['teacher_payout_identities', 'tochka_outgoing_transfers', 'teacher_transfer_matches', 'teacher_payout_evidence_links'];
    }
};
