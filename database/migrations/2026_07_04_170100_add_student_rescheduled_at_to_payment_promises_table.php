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
            // Когда студент сам перенёс дату обещания (self-service). Не null →
            // повторный перенос студентом запрещён (дальше — через куратора).
            $table->timestamp('student_rescheduled_at')->nullable()->after('promised_at');
        });
    }

    public function down(): void
    {
        Schema::table('payment_promises', function (Blueprint $table) {
            $table->dropColumn('student_rescheduled_at');
        });
    }
};
