<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H3243 — указатель живых книг кабинета (каталог /admin/documentation).
 * Не путать с admin_documents (H2570, «Важные файлы»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_docs', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('description')->nullable();
            $table->string('audience', 32);
            $table->string('route_name')->nullable();
            $table->string('url_path')->nullable();
            $table->string('faq_fragment')->nullable();
            $table->string('source_path', 255)->nullable();
            $table->string('quiz_audience', 32)->nullable();
            $table->string('access_gate', 32)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_seeded')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_docs');
    }
};
