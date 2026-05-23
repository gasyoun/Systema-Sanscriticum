<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('lead_id')
                ->nullable()
                ->after('user_id')
                ->constrained('leads')
                ->nullOnDelete();

            $table->timestamp('deposit_consumed_at')
                ->nullable()
                ->after('tariff');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['lead_id']);
            $table->dropColumn(['lead_id', 'deposit_consumed_at']);
        });
    }
};
