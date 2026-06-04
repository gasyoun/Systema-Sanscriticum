<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // default true — грандфазер для существующих юзеров, чтобы рассылка анонсов им не отвалилась
            $table->boolean('wants_email_announcements')->default(true)->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('wants_email_announcements');
        });
    }
};
