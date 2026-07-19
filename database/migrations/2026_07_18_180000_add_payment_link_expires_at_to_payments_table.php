<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->timestamp('payment_link_expires_at')->nullable()->after('transaction_id');
            $table->index(
                ['promo_code_id', 'status', 'payment_link_expires_at'],
                'payments_promo_status_link_expiry_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex('payments_promo_status_link_expiry_idx');
            $table->dropColumn('payment_link_expires_at');
        });
    }
};
