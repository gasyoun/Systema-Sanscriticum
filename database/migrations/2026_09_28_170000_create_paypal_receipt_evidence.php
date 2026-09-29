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
        Schema::create('paypal_receipt_evidence', function (Blueprint $t): void {
            $t->id();
            $t->string('transaction_id', 191)->unique();
            $t->date('completed_on')->index();
            $t->char('currency', 3);
            $t->bigInteger('gross_minor');
            $t->bigInteger('fee_minor');
            $t->bigInteger('net_minor');
            $t->char('payer_digest', 64);
            $t->char('item_digest', 64);
            $t->char('source_file_sha256', 64);
            $t->char('payload_fingerprint', 64);
            $t->timestamp('imported_at');
        });

        Schema::create('paypal_payment_evidence_links', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('receipt_id')->constrained('paypal_receipt_evidence');
            $t->foreignId('payment_id')->unique()->constrained('payments');
            $t->unsignedBigInteger('confirmed_by')->nullable();
            $t->timestamp('created_at');
            $t->unique(['receipt_id', 'payment_id']);
        });

        $this->triggers('paypal_receipt_evidence');
        $this->triggers('paypal_payment_evidence_links');
    }

    public function down(): void
    {
        foreach (['paypal_payment_evidence_links', 'paypal_receipt_evidence'] as $table) {
            $this->dropTriggers($table);
        }
        Schema::dropIfExists('paypal_payment_evidence_links');
        Schema::dropIfExists('paypal_receipt_evidence');
    }

    private function triggers(string $table): void
    {
        $driver = DB::connection()->getDriverName();
        foreach (['UPDATE', 'DELETE'] as $event) {
            $name = "paypal_ev_{$table}_".strtolower($event);
            $message = "PayPal evidence {$table} is append-only";
            if ($driver === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW BEGIN SELECT RAISE(ABORT, '{$message}'); END");
            } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END");
            } else {
                throw new RuntimeException("PayPal evidence triggers: unsupported driver {$driver}");
            }
        }
    }

    private function dropTriggers(string $table): void
    {
        foreach (['UPDATE', 'DELETE'] as $event) {
            DB::unprepared('DROP TRIGGER IF EXISTS paypal_ev_'.$table.'_'.strtolower($event));
        }
    }
};
