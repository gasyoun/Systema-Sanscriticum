<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H3532 — единственная пишущая поверхность годового календаря выплат.
 * Ручные снапшоты балансов (тип 'paypal_balance') и курса € ('fx_eur_rub').
 * Никогда не пишет teacher_payouts / payments / users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3)->default('EUR');
            $table->timestamp('entered_at');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['type', 'entered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_snapshots');
    }
};
