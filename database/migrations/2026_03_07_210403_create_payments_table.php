<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            
            // Кто платит (связь с таблицей users)
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            
            // За какой курс платит (связь с таблицей landing_pages)
            $table->foreignId('landing_page_id')->constrained('landing_pages')->cascadeOnDelete();
            
            // Сумма платежа (до 10 знаков, 2 после запятой для копеек)
            $table->decimal('amount', 10, 2);
            
            // Статус: pending (ожидает), paid (оплачено), canceled (отменено)
            $table->string('status')->default('pending');
            
            // Номер транзакции в банке (появится после реальной оплаты)
            $table->string('transaction_id')->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};