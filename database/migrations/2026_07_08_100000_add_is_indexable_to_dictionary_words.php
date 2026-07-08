<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEO P2 Wave-1 (H210, Track A / decision D1): curated-core allowlist column.
 *
 * `is_indexable` is the persisted curated-core flag. Default FALSE (default-closed):
 * a word page is promoted to `index,follow` only after it is explicitly marked — by
 * `dictionary:mark-core-indexable` fed the «Сборное ядро» / DCS-attested headword
 * list — AND `config('dictionary_seo.gate.curated_only')` is on. Until the list is
 * fed, nothing indexes by accident even with the master switch flipped.
 *
 * Non-breaking: nullable-safe boolean default false, so a bare `migrate` on prod
 * changes no existing behaviour (Wave 0 stays all-noindex until the switch flips).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dictionary_words', function (Blueprint $table) {
            $table->boolean('is_indexable')->default(false)->after('wikidata_qid');
            $table->index('is_indexable');
        });
    }

    public function down(): void
    {
        Schema::table('dictionary_words', function (Blueprint $table) {
            $table->dropIndex(['is_indexable']);
            $table->dropColumn('is_indexable');
        });
    }
};
