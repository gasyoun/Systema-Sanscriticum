<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Провенанс моста «Расход» → opex (H2003, issue #953): расход, созданный из
     * легаси-платежа с тарифом «Расход», несет id исходного платежа. Уникальный
     * индекс делает мост идемпотентным — один платеж не может стать двумя
     * расходами. Ручные расходы остаются с NULL.
     */
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('payment_id')->nullable()->unique()
                ->constrained('payments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_id');
        });
    }
};
