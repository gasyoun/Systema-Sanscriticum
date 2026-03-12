<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_loyalty_active')->default(false);
            $table->integer('loyalty_discount_percent')->default(15); // Например, 15%
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_settings');
    }
};