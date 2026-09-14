<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// H4434 — timezone localization for non-MSK students.
//
// timezone        — постоянная IANA-зона ученика (nullable: нет = МСК-дефолт).
// tz_override     — временная зона (временное пребывание, напр. Индия на 1.5 мес).
// tz_override_until — дата окончания пребывания (после неё оверрайд игнорируется,
//                     ленивый возврат, без крона).
// tz_source       — откуда взята постоянная зона: manual / device / admin.
//
// Эффективная зона вычисляется в User::effectiveTimezone() — см. модель.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('timezone', 64)->nullable()->after('country');
            $table->string('tz_override', 64)->nullable()->after('timezone');
            $table->date('tz_override_until')->nullable()->after('tz_override');
            $table->string('tz_source', 16)->nullable()->after('tz_override_until');

            $table->index('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['timezone']);
            $table->dropColumn(['timezone', 'tz_override', 'tz_override_until', 'tz_source']);
        });
    }
};
