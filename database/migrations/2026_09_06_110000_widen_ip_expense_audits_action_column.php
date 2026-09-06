<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H4200 follow-up 06-09-2026: `imported_statement` (18 символов) не влез в
 * `ip_expense_audits.action` varchar(16) — прод-MySQL в strict-mode честно
 * REFUSE-ит (SQLSTATE 22001), sqlite-тесты длину не проверяют. 32 — с запасом
 * под будущие действия аудита.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Без ->index(): индекс action_index уже существует и переживает change()
        // и на MySQL (MODIFY не трогает индекс), и на sqlite (переносится при
        // rebuild) — повторное create index падает (CI, 06-09-2026).
        Schema::table('ip_expense_audits', function (Blueprint $table): void {
            $table->string('action', 32)->change();
        });
    }

    public function down(): void
    {
        Schema::table('ip_expense_audits', function (Blueprint $table): void {
            $table->string('action', 16)->change();
        });
    }
};
