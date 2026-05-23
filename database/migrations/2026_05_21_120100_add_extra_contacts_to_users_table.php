<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('max_user_id', 64)->nullable()->after('vk_id');
            $table->string('instagram', 120)->nullable()->after('max_user_id');
            $table->string('facebook', 120)->nullable()->after('instagram');

            $table->index('max_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['max_user_id']);
            $table->dropColumn(['max_user_id', 'instagram', 'facebook']);
        });
    }
};
