<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_payouts', function (Blueprint $table) {
            // Тип выплаты: обычная или аванс. Аванс висит как «выдан, не зачтён»
            // (settled_at = null) и НЕ уменьшает «К выплате», пока его явно не
            // зачтут при полной выплате.
            $table->string('type')->default('regular')->after('amount'); // regular | advance
            $table->timestamp('settled_at')->nullable()->after('type');
            $table->foreignId('settled_by')->nullable()->after('settled_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('teacher_payouts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('settled_by');
            $table->dropColumn(['type', 'settled_at']);
        });
    }
};
