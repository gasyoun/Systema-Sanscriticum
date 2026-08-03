<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Дожим-дрип (H2059, IMPLEMENTATION H-B п.5): линейная последовательность
     * шаблонов day0/day3/day7 для категории `dozhim`. NULL — шаблон в
     * авто-дрипе не участвует (например, апселл-заготовка отправляется
     * оператором вручную). Аддитивно, ни на что не влияет пока dozhim_drip OFF.
     */
    public function up(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->string('dozhim_step', 8)->nullable()->index()->after('suggester_category');
        });
    }

    public function down(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->dropColumn('dozhim_step');
        });
    }
};
