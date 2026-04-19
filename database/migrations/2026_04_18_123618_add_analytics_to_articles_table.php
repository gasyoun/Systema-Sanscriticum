<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->string('yandex_metrika_id', 20)->nullable()->after('meta_description');
            $table->string('vk_pixel_id', 20)->nullable()->after('yandex_metrika_id');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn(['yandex_metrika_id', 'vk_pixel_id']);
        });
    }
};