<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            
            // Сам код, который вводит юзер (например, SPRING2026)
            $table->string('code')->unique();
            
            // Тип скидки: percent (процент) или fixed (фиксированная сумма в рублях)
            $table->string('type')->default('percent');
            
            // Размер скидки (например, 20 для процентов, или 1000 для рублей)
            $table->decimal('value', 10, 2);
            
            // Ограничения (необязательные)
            $table->integer('usage_limit')->nullable(); // Максимум активаций (например, только для первых 50)
            $table->integer('used_count')->default(0);  // Сколько раз уже применили
            $table->timestamp('expires_at')->nullable(); // Срок действия (до 15 марта)
            
            $table->boolean('is_active')->default(true); // Рубильник вкл/выкл
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_codes');
    }
};