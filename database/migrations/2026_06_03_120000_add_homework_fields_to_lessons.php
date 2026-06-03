<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            if (! Schema::hasColumn('lessons', 'homework_enabled')) {
                $table->boolean('homework_enabled')->default(false)->after('flash_cards');
            }
            if (! Schema::hasColumn('lessons', 'homework_prompt')) {
                $table->text('homework_prompt')->nullable()->after('homework_enabled');
            }
            if (! Schema::hasColumn('lessons', 'homework_attachments')) {
                $table->json('homework_attachments')->nullable()->after('homework_prompt');
            }
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn(['homework_enabled', 'homework_prompt', 'homework_attachments']);
        });
    }
};
