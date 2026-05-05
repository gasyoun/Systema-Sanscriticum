<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->integer('prana_balance')->default(0)->after('total_lessons_opened');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->integer('prana_spent')->default(0)->after('amount');
        });

        Schema::create('prana_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('amount');
            $table->string('reason', 50);
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['source_type', 'source_id']);

            $table->unique(
                ['user_id', 'reason', 'source_type', 'source_id'],
                'prana_tx_idempotency'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prana_transactions');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('prana_spent');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('prana_balance');
        });
    }
};
