<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H1755 residual: some courses never get a live repeat (known ahead). Curators
 * say so explicitly; students buy recording instead of waiting for «по набору».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->boolean('never_repeat')
                ->default(false)
                ->after('is_completed');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('never_repeat');
        });
    }
};
