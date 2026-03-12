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
    Schema::create('landing_pages', function (Blueprint $table) {
        $table->id();

        // 1. Адрес страницы (например: 'sanskrit-start')
        $table->string('slug')->unique();
        $table->boolean('is_active')->default(true);

        // 2. Контент
        $table->string('title');            // Заголовок (H1)
        $table->string('instructor_name')->nullable(); // Преподаватель
        $table->text('description')->nullable(); // Описание курса (Rich Text)
        $table->string('image_path')->nullable(); // Обложка
        $table->string('video_url')->nullable();  // Ссылка на YouTube/RuTube

        // 3. Действие (Кнопка)
        // Если заполнено telegram_url — кнопка ведет туда.
        // Если пусто — показываем форму сбора Лида.
        $table->string('telegram_url')->nullable(); 
        $table->string('button_text')->default('Записаться на курс');

        // 4. Маркетинг
        $table->string('yandex_metrika_id')->nullable(); // Номер счетчика (XXXXXXXX)
        
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('landing_pages');
    }
};
