<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Дедуп напоминаний менеджеру о лидах: когда по лиду последний раз слали пинг
 * «пора связаться». Не даёт слать повторно в тот же день (leads:remind-followup).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable()->after('next_contact_at');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('reminded_at');
        });
    }
};
