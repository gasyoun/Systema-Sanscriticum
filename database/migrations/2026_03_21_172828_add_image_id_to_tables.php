<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Список таблиц, в которые нужно добавить связь с медиатекой.
     * Впиши сюда все нужные таблицы (кроме тех, где картинки лежат в JSON-блоках).
     */
    protected array $tables = [
        'courses',              // Курсы (точно есть картинки)
        'landing_pages',        // Лендинги
        'lessons',              // Уроки
        'users',                // Пользователи (возможно, аватарки?)
        'certificates',         // Сертификаты (возможно, фоны/печати?)
        'chat_messages',
        'dictionaries',
        'dictionary_words',
        'groups',
        'leads',
        'marketing_settings',
        'payments',
        'promo_codes',
        'schedules',
        'tariffs',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            // Проверяем, существует ли таблица, чтобы миграция не упала с ошибкой
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    // Защита: добавляем колонку, только если её там ещё нет
                    if (!Schema::hasColumn($tableName, 'image_id')) {
                        $table->foreignId('image_id')
                              ->nullable()
                              ->constrained('media') // Связываем с таблицей Curator
                              ->nullOnDelete();      // Если картинку удалят, тут станет null (ничего не сломается)
                    }
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    if (Schema::hasColumn($tableName, 'image_id')) {
                        // Сначала безопасно отвязываем внешний ключ, затем удаляем колонку
                        $table->dropForeign(['image_id']);
                        $table->dropColumn('image_id');
                    }
                });
            }
        }
    }
};