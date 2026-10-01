<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Фискализация через Digital Kassa (features.digitalkassa_receipts).
 * payments.fiscal_provider — кто пробивает чек по ЭТОМУ платежу (tochka|digitalkassa),
 * фиксируется при создании ссылки; fiscal_receipts — состояние чека DK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $t): void {
            $t->string('fiscal_provider', 20)->nullable()->after('payment_method');
        });

        Schema::create('fiscal_receipts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('payment_id')->unique()->constrained('payments')->cascadeOnDelete();
            $t->string('provider', 20);
            $t->unsignedInteger('attempt')->default(1);
            $t->string('status', 20)->default('pending')->index();
            $t->string('item_name', 128);
            $t->unsignedTinyInteger('payment_method');
            $t->json('request_json')->nullable();
            $t->unsignedBigInteger('fiscal_num')->nullable();
            $t->unsignedBigInteger('fiscal_sign')->nullable();
            $t->string('receipt_url', 512)->nullable();
            $t->timestamp('registered_at')->nullable();
            $t->text('last_error')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_receipts');

        Schema::table('payments', function (Blueprint $t): void {
            $t->dropColumn('fiscal_provider');
        });
    }
};
