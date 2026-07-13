<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add a composite FULLTEXT index over the four searched columns of
 * dictionary_words so the словарь search (App\Livewire\StudentDictionary) can
 * use MATCH ... AGAINST instead of four OR'd `LIKE '%term%'` scans.
 *
 * Leading-wildcard LIKE cannot use a B-tree index, so today every keystroke is
 * a full table scan of dictionary_words across all dictionaries. The existing
 * single-column b-tree indexes on devanagari/iast/cyrillic do NOT help a
 * `%term%` predicate; `translation` is not indexed at all.
 *
 * FULLTEXT is MySQL-only. On SQLite (the test DB) and any other driver this
 * migration is a no-op and the component keeps its LIKE fallback, so the test
 * suite and non-MySQL environments are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return; // FULLTEXT unsupported outside MySQL; LIKE fallback stays in effect.
        }

        Schema::table('dictionary_words', function (Blueprint $table) {
            $table->fullText(
                ['devanagari', 'iast', 'cyrillic', 'translation'],
                'dictionary_words_fulltext',
            );
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('dictionary_words', function (Blueprint $table) {
            $table->dropFullText('dictionary_words_fulltext');
        });
    }
};
