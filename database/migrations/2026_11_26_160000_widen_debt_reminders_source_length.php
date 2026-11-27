<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `debt_reminders.source` был varchar(16), а источники контакта не помещаются:
 * ручная рассылка с экрана «Участники по блокам» пишет `block_participants`
 * (18 символов), и MySQL в strict-режиме отвечал 1406 «Data too long» — 500
 * уже ПОСЛЕ того, как сообщения ушли в каналы.
 *
 * 32 символа с запасом: там же живут `auto`, `manual` и будущие источники
 * («reactivation», «block_participants»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debt_reminders', function (Blueprint $table): void {
            $table->string('source', 32)->change();
        });
    }

    public function down(): void
    {
        Schema::table('debt_reminders', function (Blueprint $table): void {
            $table->string('source', 16)->change();
        });
    }
};
