<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tariffs', function (Blueprint $table) {
            // Заполнено только у тарифов-половин блока (1 или 2). NULL = тариф на
            // весь блок (type='block') или иной тип (full/vip/bundle).
            $table->unsignedTinyInteger('block_half')->nullable()->after('block_number');
        });
    }

    public function down(): void
    {
        Schema::table('tariffs', function (Blueprint $table) {
            $table->dropColumn('block_half');
        });
    }
};
