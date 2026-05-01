<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landing_pages', function (Blueprint $table): void {
            $table->string('redirect_after_submit_url', 500)
                ->nullable()
                ->after('telegram_url');
        });
    }

    public function down(): void
    {
        Schema::table('landing_pages', function (Blueprint $table): void {
            $table->dropColumn('redirect_after_submit_url');
        });
    }
};