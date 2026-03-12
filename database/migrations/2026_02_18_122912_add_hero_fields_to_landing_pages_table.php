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
    Schema::table('landing_pages', function (Blueprint $table) {
        $table->string('subtitle')->nullable();
        $table->text('hero_description')->nullable();
        $table->string('bullet_1')->nullable();
        $table->string('bullet_2')->nullable();
        $table->string('button_subtext')->nullable();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('landing_pages', function (Blueprint $table) {
            //
        });
    }
};
