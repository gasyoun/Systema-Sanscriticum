<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_support_accounts', function (Blueprint $table) {
            $table->json('sync_state')->nullable()->after('last_synced_at');
            $table->timestamp('last_successful_sync_at')->nullable()->after('sync_state');
            $table->text('last_sync_error')->nullable()->after('last_successful_sync_at');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_support_accounts', function (Blueprint $table) {
            $table->dropColumn(['sync_state', 'last_successful_sync_at', 'last_sync_error']);
        });
    }
};
