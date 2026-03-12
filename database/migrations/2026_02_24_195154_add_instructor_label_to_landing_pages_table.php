<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
    Schema::table('landing_pages', function (Blueprint $table) {
        // Добавляем поле с дефолтным значением "с экспертом"
        $table->string('instructor_label')->nullable()->default('с экспертом')->after('subtitle');
    });
}

public function down()
{
    Schema::table('landing_pages', function (Blueprint $table) {
        $table->dropColumn('instructor_label');
    });
}

};
