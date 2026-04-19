<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Для изменения колонки нужен пакет doctrine/dbal — он у Laravel 11 уже встроен,
        // в более ранних версиях ставится вручную: composer require doctrine/dbal
        Schema::table('leads', function (Blueprint $table) {
            $table->unsignedBigInteger('landing_page_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->unsignedBigInteger('landing_page_id')->nullable(false)->change();
        });
    }
};