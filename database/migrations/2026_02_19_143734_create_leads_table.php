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
    Schema::create('leads', function (Blueprint $table) {
        $table->id();
        // Важно: landing_page_id должен ссылаться на таблицу landing_pages
        $table->foreignId('landing_page_id')->constrained()->onDelete('cascade');
        $table->string('name');
        $table->string('contact');
        $table->boolean('is_promo_agreed')->default(false);
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
