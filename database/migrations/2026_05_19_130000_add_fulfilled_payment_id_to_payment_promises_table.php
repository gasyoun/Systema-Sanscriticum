<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_promises', function (Blueprint $table) {
            $table->foreignId('fulfilled_payment_id')
                ->nullable()
                ->after('cancelled_at')
                ->constrained('payments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_promises', function (Blueprint $table) {
            $table->dropForeign(['fulfilled_payment_id']);
            $table->dropColumn('fulfilled_payment_id');
        });
    }
};
