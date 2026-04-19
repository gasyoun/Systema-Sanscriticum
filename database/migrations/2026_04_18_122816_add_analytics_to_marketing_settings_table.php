<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->string('blog_yandex_metrika_id', 20)->nullable()->after('wholesale_large_discount');
            $table->string('blog_vk_pixel_id', 20)->nullable()->after('blog_yandex_metrika_id');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->dropColumn(['blog_yandex_metrika_id', 'blog_vk_pixel_id']);
        });
    }
};