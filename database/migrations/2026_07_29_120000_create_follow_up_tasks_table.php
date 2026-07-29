<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GC-C3 (H1836) — ЗАДАЧА МЕНЕДЖЕРУ как настоящий объект.
 *
 * До сих пор «следующий контакт» жил парой полей на лиде
 * (`leads.next_contact_at` + `leads.assigned_to`): один контакт на человека,
 * без типа, без отметки «сделано» и без истории. Задача становится строкой —
 * их может быть несколько, у каждой свой срок, тип и факт закрытия.
 *
 * Вешается на СДЕЛКУ (GC-C1 / H1641), а не на лид: сделка — единица работы
 * воронки, у одного человека их может быть несколько (спека
 * docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md §2.1, рулинг роадмапа
 * «после GC-C1 задачи вешаются на Deal, не на Lead»).
 *
 * Аддитивно: `leads`, `deals`, `payments` не меняются ни одной колонкой, а
 * `RemindLeadsForFollowup`/`crm_reminders` работают ровно как раньше — новая
 * поверхность живёт за СВОИМ флагом `crm_follow_up_tasks` (спека §7 F6:
 * переиспользование `crm_reminders` молча расширило бы смысл одного рубильника).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_up_tasks', function (Blueprint $table) {
            $table->id();
            // Задача без сделки бессмысленна: удалили сделку — уносим её задачи.
            $table->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            // Ответственный менеджер. NULL = ничья задача (видна всем в кокпите),
            // зеркало `deals.assigned_to`.
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            // 'call' | 'message' | 'meeting' | 'other' — см. FollowUpTask::types().
            $table->string('type', 32)->default('call');
            // Срок. Обязателен: задача без срока не попадёт ни в один бакет
            // «на сегодня» и потому не является задачей в смысле GC-C3.
            $table->timestamp('due_at');
            // Единственный признак закрытия. NULL = открыта.
            $table->timestamp('done_at')->nullable();
            $table->timestamps();

            // Бакет кокпита: открытые задачи с наступившим сроком.
            $table->index(['done_at', 'due_at']);
            $table->index('assigned_to');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_up_tasks');
    }
};
