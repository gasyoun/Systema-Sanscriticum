<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H3821 — published, fixed EUR/USD price per tariff, refreshed monthly by
 * paypal:refresh-foreign-prices. Never touches payments/payment_audits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tariff_foreign_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tariff_id')->constrained()->cascadeOnDelete();
            $table->string('currency', 3);
            $table->decimal('price', 10, 2);
            $table->decimal('fx_rate', 12, 4);
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->unique(['tariff_id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tariff_foreign_prices');
    }
};
