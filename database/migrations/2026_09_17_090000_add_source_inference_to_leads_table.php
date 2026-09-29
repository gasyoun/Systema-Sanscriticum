<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H5021 — у каждого лида должен быть источник. Additive:
 *  - source             — источник, введённый человеком (CRM); машина не перетирает никогда;
 *  - inferred_source    — источник, выведенный ночной командой leads:infer-source
 *                         из UTM / статьи / referrer / кабинета / канала лид-магнита;
 *  - inference_rule     — имя правила, по которому выведен inferred_source (аудит);
 *  - source_inferred_at — когда выведен.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('source', 64)->nullable()->after('utm_term');
            $table->string('inferred_source', 64)->nullable()->after('source');
            $table->string('inference_rule', 32)->nullable()->after('inferred_source');
            $table->timestamp('source_inferred_at')->nullable()->after('inference_rule');
            $table->index('inferred_source');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['inferred_source']);
            $table->dropColumn(['source', 'inferred_source', 'inference_rule', 'source_inferred_at']);
        });
    }
};
