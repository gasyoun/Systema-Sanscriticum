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
    Schema::create('dictionary_words', function (Blueprint $table) {
        $table->id();
        $table->foreignId('dictionary_id')->constrained()->cascadeOnDelete();
        $table->string('devanagari')->nullable(); // सत्
        $table->string('iast')->nullable();       // sat
        $table->string('cyrillic')->nullable();   // сат
        $table->text('translation');              // Истина, бытие...
        $table->string('page')->nullable();       // Стр. 142
        $table->timestamps();
        
        // Индексы для быстрого поиска
        $table->index('devanagari');
        $table->index('iast');
        $table->index('cyrillic');
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dictionary_words');
    }
};
