<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('preview');
            $table->string('button_text')->nullable()->after('content');
            $table->string('button_url')->nullable()->after('button_text');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn(['image_path', 'button_text', 'button_url']);
        });
    }
};