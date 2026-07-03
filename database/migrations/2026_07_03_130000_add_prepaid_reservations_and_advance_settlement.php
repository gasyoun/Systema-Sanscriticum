<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('prepaid_reserved_by_payment_id')
                ->nullable()
                ->after('deposit_consumed_at')
                ->constrained('payments')
                ->nullOnDelete();
            $table->timestamp('prepaid_reserved_at')->nullable()->after('prepaid_reserved_by_payment_id');
            $table->json('prepaid_credit_payment_ids')->nullable()->after('referral_credit_applied');
            $table->decimal('prepaid_credit_amount', 10, 2)->nullable()->after('prepaid_credit_payment_ids');

            $table->index(['user_id', 'course_id', 'prepaid_reserved_by_payment_id'], 'payments_prepaid_reservation_idx');
        });

        Schema::table('teacher_payouts', function (Blueprint $table) {
            $table->decimal('settled_amount', 10, 2)->default(0)->after('settled_by');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['prepaid_reserved_by_payment_id']);
            $table->dropIndex('payments_prepaid_reservation_idx');
            $table->dropColumn([
                'prepaid_reserved_by_payment_id',
                'prepaid_reserved_at',
                'prepaid_credit_payment_ids',
                'prepaid_credit_amount',
            ]);
        });

        Schema::table('teacher_payouts', function (Blueprint $table) {
            $table->dropColumn('settled_amount');
        });
    }
};
