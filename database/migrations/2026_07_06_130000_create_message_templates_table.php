<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Общая библиотека шаблонов сообщений оператора (H221). Один текст с
     * плейсхолдерами питает реактивацию, follow-up лидов и канреплаи поддержки.
     */
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            // lead | reactivation | support (см. App\Models\MessageTemplate)
            $table->string('category')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');
    }
};
