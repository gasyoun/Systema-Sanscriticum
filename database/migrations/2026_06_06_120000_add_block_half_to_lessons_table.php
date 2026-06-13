<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            // К какой половине блока относится урок при продаже блока «пополам».
            // NULL = урок не делится (доступен только по полному блоку/full);
            // 1 = первая половина; 2 = вторая.
            $table->unsignedTinyInteger('block_half')->nullable()->after('block_number');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn('block_half');
        });
    }
};
