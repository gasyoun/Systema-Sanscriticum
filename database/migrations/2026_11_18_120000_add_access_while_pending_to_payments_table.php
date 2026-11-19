<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B2B-оплата за группу: «открыть доступ до оплаты». Групповой платёж юрлица
 * в статусе pending с этой отметкой уже даёт ученикам группы доступ к
 * оплачиваемым блокам (флаг features.b2b_group_pending_access). В выручку и ЗП
 * платёж попадает только после перевода в «Оплачено».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->boolean('access_while_pending')->default(false)->after('is_conditional');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn('access_while_pending');
        });
    }
};
