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
        Schema::create('dictionaries', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Название: "Словарь Кочергиной"
            $table->string('description')->nullable(); // Описание
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // dictionary_words создаётся раньше (2026_02_11) без FK — MySQL 8
        // падает на constrained() к несуществующей таблице. Вешаем FK здесь.
        if (Schema::hasTable('dictionary_words')) {
            Schema::table('dictionary_words', function (Blueprint $table) {
                $table->foreign('dictionary_id')
                    ->references('id')
                    ->on('dictionaries')
                    ->cascadeOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('dictionary_words')) {
            Schema::table('dictionary_words', function (Blueprint $table) {
                $table->dropForeign(['dictionary_id']);
            });
        }

        Schema::dropIfExists('dictionaries');
    }
};
