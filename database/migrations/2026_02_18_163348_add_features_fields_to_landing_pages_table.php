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
        $table->string('features_title')->nullable();
        
        $table->string('feature_1_title')->nullable();
        $table->text('feature_1_text')->nullable();
        
        $table->string('feature_2_title')->nullable();
        $table->text('feature_2_text')->nullable();
        
        $table->string('feature_3_title')->nullable();
        $table->text('feature_3_text')->nullable();
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
