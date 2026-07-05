<?php

use App\Models\DictionaryWord;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * SEO P2 (H204): public dictionary entity pages /slovar/{slug}.
 *
 * - `slug`: per-HEADWORD url key (NOT per-row). Rows sharing the same headword
 *   get the same slug → the word page collapses them onto one canonical URL
 *   (decision D3). Not unique.
 * - `wikidata_qid`: optional Wikidata concept id for the `sameAs` triplet
 *   (decision D4). Populated later by the auto-matcher + confidence gate; the
 *   blade emits `sameAs` only when it is present.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dictionary_words', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('cyrillic');
            $table->string('wikidata_qid')->nullable()->after('slug');
            $table->index('slug');
        });

        // Backfill slugs from the existing headwords (IAST preferred — ASCII-friendly,
        // Str::slug transliterates the diacritics; fall back to cyrillic then devanagari).
        DictionaryWord::query()
            ->select(['id', 'iast', 'cyrillic', 'devanagari'])
            ->chunkById(500, function ($words) {
                foreach ($words as $word) {
                    $slug = DictionaryWord::makeHeadwordSlug($word->iast, $word->cyrillic, $word->devanagari);
                    if ($slug !== '') {
                        DictionaryWord::whereKey($word->id)->update(['slug' => $slug]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('dictionary_words', function (Blueprint $table) {
            $table->dropIndex(['slug']);
            $table->dropColumn(['slug', 'wikidata_qid']);
        });
    }
};
