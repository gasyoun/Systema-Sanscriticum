<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H6174: реестр подписочной квоты по школьным ролям — счётчики ролей на окно
 * и audit-trail резерваций. Технические поля без ПД; role_id — каноник-слаг
 * ростера (новых canonical role ID миграция не вводит).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_role_quotas', function (Blueprint $table) {
            $table->id();
            $table->string('role_id', 64);
            $table->string('window_key', 16);
            $table->unsignedBigInteger('routine_committed')->default(0);
            $table->unsignedBigInteger('routine_reserved')->default(0);
            $table->unsignedBigInteger('check_committed')->default(0);
            $table->unsignedBigInteger('check_reserved')->default(0);
            $table->unsignedBigInteger('reserve_committed')->default(0);
            $table->unsignedBigInteger('reserve_reserved')->default(0);
            $table->timestamps();
            $table->unique(['role_id', 'window_key']);
        });

        Schema::create('school_role_quota_reservations', function (Blueprint $table) {
            $table->id();
            $table->string('request_id', 128)->unique();
            $table->string('role_id', 64);
            $table->string('window_key', 16);
            $table->string('lane', 16);
            $table->boolean('is_experiment')->default(false);
            $table->unsignedBigInteger('units');
            $table->string('status', 16)->default('reserved');
            $table->unsignedBigInteger('consumed_units')->nullable();
            $table->timestamps();
            $table->index(['role_id', 'window_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_role_quota_reservations');
        Schema::dropIfExists('school_role_quotas');
    }
};
