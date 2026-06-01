<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('direct_ad_spends', function (Blueprint $table) {
            $table->id();
            $table->date('month')->index(); // первое число месяца
            $table->decimal('specialist_salary', 12, 2)->default(0);
            $table->decimal('ad_budget', 12, 2)->default(0);
            $table->string('utm_source')->nullable();
            $table->foreignId('landing_page_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direct_ad_spends');
    }
};
