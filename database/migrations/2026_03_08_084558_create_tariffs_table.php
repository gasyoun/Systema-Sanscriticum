<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tariffs', function (Blueprint $table) {
            $table->id();
            
            // Привязка к курсу (nullable, чтобы в будущем делать "Бандлы" из нескольких курсов без жесткой привязки к одному)
            $table->foreignId('course_id')->nullable()->constrained()->cascadeOnDelete();
            
            // Название тарифа (например: "Блок 1 (Занятия 1-4)", "VIP с куратором")
            $table->string('title');
            
            // Тип тарифа: full (весь курс), block (кусочек), vip (с куратором), bundle (пакет)
            $table->string('type')->default('full');
            
            // Если это type = 'block', здесь будет цифра 1, 2, 3 и т.д.
            $table->integer('block_number')->nullable();
            
            // Финансы
            $table->decimal('price', 10, 2); // Актуальная цена (например, 4000.00)
            $table->decimal('old_price', 10, 2)->nullable(); // Зачеркнутая цена для маркетинга (например, 5000.00)
            
            // Описание (что входит в тариф, можно выводить списком на лендинге)
            $table->text('description')->nullable();
            
            // Статус (чтобы можно было скрыть тариф из продажи, не удаляя его)
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tariffs');
    }
};