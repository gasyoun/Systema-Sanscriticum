<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            // Удаляем старую неактуальную колонку
            $table->dropColumn('loyalty_discount_percent');

            // Добавляем новые колонки для умной лояльности
            $table->integer('bundle_2_discount')->default(10)->after('is_loyalty_active');
            $table->integer('bundle_3_discount')->default(15)->after('bundle_2_discount');
            
            $table->integer('wholesale_small_threshold')->default(5)->after('bundle_3_discount');
            $table->integer('wholesale_small_discount')->default(10)->after('wholesale_small_threshold');
            
            $table->integer('wholesale_large_threshold')->default(10)->after('wholesale_small_discount');
            $table->integer('wholesale_large_discount')->default(15)->after('wholesale_large_threshold');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            // В случае отката миграции возвращаем как было
            $table->integer('loyalty_discount_percent')->default(15);
            
            $table->dropColumn([
                'bundle_2_discount',
                'bundle_3_discount',
                'wholesale_small_threshold',
                'wholesale_small_discount',
                'wholesale_large_threshold',
                'wholesale_large_discount',
            ]);
        });
    }
};