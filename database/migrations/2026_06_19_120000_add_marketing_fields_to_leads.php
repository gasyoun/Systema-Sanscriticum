<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // Статус воронки (значения — Lead::STATUSES). По умолчанию новый.
            $table->string('status')->default('new')->index()->after('converted_at');
            // Ответственный менеджер.
            $table->foreignId('assigned_to')->nullable()->after('status')
                ->constrained('users')->nullOnDelete();
            // Дата следующего контакта (для напоминаний менеджеру).
            $table->date('next_contact_at')->nullable()->after('assigned_to');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropColumn(['status', 'next_contact_at']);
        });
    }
};
