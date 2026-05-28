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
            $table->boolean('is_conditional')
                ->default(false)
                ->after('status');

            $table->foreignId('linked_promise_id')
                ->nullable()
                ->after('is_conditional')
                ->constrained('payment_promises')
                ->nullOnDelete();

            $table->index('is_conditional');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['linked_promise_id']);
            $table->dropIndex(['is_conditional']);
            $table->dropColumn(['is_conditional', 'linked_promise_id']);
        });
    }
};
