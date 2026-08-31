<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DictionaryWord;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * H3762 residual / issue #2265 — one-shot repair of the 14 Фриш rows whose
 * IAST/Devanagari/Cyrillic carry Cyrillic OCR homoglyphs (а с р к г б inside
 * Latin lemmas; the derived columns inherited the corruption because the
 * converter passed unknown chars through).
 *
 * Contract (the H3773/H3807 recompute-at-apply-time school):
 *  - every targeted column must EXACTLY match the recorded corrupted value at
 *    apply time — any drift refuses the WHOLE batch inside one transaction;
 *  - without --apply only the plan is printed;
 *  - slugs are re-derived through DictionaryWord::makeHeadwordSlug() (the
 *    model's own path), never hand-written; old → new is reported because a
 *    slug change retires the old /slovar URL (Wave-0 noindex, no aliases kept);
 *  - the two ⟨б⟩ rows (112501 /расб/, 115974 /sadб/) are deliberately ABSENT —
 *    their reading needs the printed Фриш page (a human decides in #2265).
 *
 * Intended readings were verified against each row's own Russian gloss —
 * table in https://github.com/gasyoun/Systema-Sanscriticum/issues/2265;
 * Devanagari recomputed via canonical sanskrit-util slp1_to_devanagari(to_slp1()).
 */
class FixFrischHomoglyphs extends Command
{
    protected $signature = 'slovar:fix-frisch-homoglyphs {--apply : Write the changes (default: print the plan)} {--only= : Comma-separated row ids to restrict the batch (tests)}';

    protected $description = 'Repair the 14 certain Cyrillic-homoglyph rows of the Фриш dictionary (issue #2265)';

    /** @var array<int, array<string, array{0: string, 1: string}>> id => column => [expected, new] */
    public const FIXES = [
        111737 => [
            'iast' => ['/ргаdā/', '/pradā/'],
            'devanagari' => ['/ргаदा/', '/प्रदा/'],
            'cyrillic' => ['/ргада/', '/прада/'],
        ],
        111956 => [
            'iast' => ['/dorака/', '/doraka/'],
            'devanagari' => ['/दोर्ака/', '/दोरक/'],
        ],
        111988 => [
            'iast' => ['/drumа/', '/druma/'],
            'devanagari' => ['/द्रुम्а/', '/द्रुम/'],
        ],
        112434 => [
            'iast' => ['/араnī/', '/apanī/'],
            'devanagari' => ['/араनी/', '/अपनी/'],
            'cyrillic' => ['/арани/', '/апани/'],
        ],
        112441 => [
            'iast' => ['/nīса/', '/nīca/'],
            'devanagari' => ['/नीса/', '/नीच/'],
            'cyrillic' => ['/ниса/', '/нича/'],
        ],
        112530 => [
            'iast' => ['/paṇ/ /paṇati/ /paṇate/ /paṇitа/', '/paṇ/ /paṇati/ /paṇate/ /paṇita/'],
            'devanagari' => ['/पण्/ /पणति/ /पणते/ /पणित्а/', '/पण्/ /पणति/ /पणते/ /पणित/'],
        ],
        112638 => [
            'iast' => ['/parāñc/ /parāсī/', '/parāñc/ /parācī/'],
            'devanagari' => ['/पराञ्च्/ /पराсई/', '/पराञ्च्/ /पराची/'],
            'cyrillic' => ['/паранч/ /параси/', '/паранч/ /парачи/'],
        ],
        112654 => [
            'iast' => ['/parikhīkṛta/ /sāgага/', '/parikhīkṛta/ /sāgara/'],
            'devanagari' => ['/परिखीकृत/ /साग्ага/', '/परिखीकृत/ /सागर/'],
            'cyrillic' => ['/парикхикрита/ /сагага/', '/парикхикрита/ /сагара/'],
        ],
        113083 => [
            'iast' => ['/pratyañe/ /pratyañ/ /pratīсī/ /pratyak/', '/pratyañe/ /pratyañ/ /pratīcī/ /pratyak/'],
            'devanagari' => ['/प्रत्यञे/ /प्रत्यञ्/ /प्रतीсई/ /प्रत्यक्/', '/प्रत्यञे/ /प्रत्यञ्/ /प्रतीची/ /प्रत्यक्/'],
            'cyrillic' => ['/пратьянье/ /пратьянь/ /пратиси/ /пратьяк/', '/пратьянье/ /пратьянь/ /пратичи/ /пратьяк/'],
        ],
        113326 => [
            'iast' => ['/bаkа/', '/baka/'],
            'devanagari' => ['/ब्аक्а/', '/बक/'],
        ],
        113381 => [
            'iast' => ['/араbādh/', '/apabādh/'],
            'devanagari' => ['/араबाध्/', '/अपबाध्/'],
            'cyrillic' => ['/арабадх/', '/апабадх/'],
        ],
        113459 => [
            'iast' => ['/араbrū/', '/apabrū/'],
            'devanagari' => ['/араब्रू/', '/अपब्रू/'],
            'cyrillic' => ['/арабру/', '/апабру/'],
        ],
        114071 => [
            'iast' => ['/mṛgayāṃ/ /сar/', '/mṛgayāṃ/ /car/'],
            'devanagari' => ['/मृगयां/ /сअर्/', '/मृगयां/ /चर्/'],
            'cyrillic' => ['/мригаян/ /сар/', '/мригаян/ /чар/'],
        ],
        114168 => [
            'iast' => ['/yatra/ /kva/ /са/', '/yatra/ /kva/ /ca/'],
            'devanagari' => ['/यत्र/ /क्व/ /са/', '/यत्र/ /क्व/ /च/'],
            'cyrillic' => ['/ятра/ /ква/ /са/', '/ятра/ /ква/ /ча/'],
        ],
    ];

    public function handle(): int
    {
        $only = array_filter(array_map('intval', explode(',', (string) $this->option('only'))));
        $fixes = $only === []
            ? self::FIXES
            : array_intersect_key(self::FIXES, array_flip($only));

        if ($fixes === []) {
            $this->error('Nothing to do: --only matched no known row ids.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $drift = [];
        $plan = [];

        $rows = DictionaryWord::whereIn('id', array_keys($fixes))->get()->keyBy('id');

        foreach ($fixes as $id => $columns) {
            $word = $rows->get($id);
            if ($word === null) {
                $drift[] = "id {$id}: row is GONE";

                continue;
            }
            foreach ($columns as $col => [$expected, $new]) {
                $current = (string) $word->{$col};
                if ($current !== $expected) {
                    $drift[] = "id {$id} {$col}: expected «{$expected}», found «{$current}»";
                } else {
                    $plan[] = "id {$id} {$col}: «{$expected}» → «{$new}»";
                }
            }
        }

        if ($drift !== []) {
            $this->error('REFUSED — the live rows have drifted from the recorded corruption; nothing was changed:');
            foreach ($drift as $line) {
                $this->line('  '.$line);
            }

            return self::FAILURE;
        }

        foreach ($plan as $line) {
            $this->line(($apply ? 'APPLY ' : 'PLAN  ').$line);
        }

        if (! $apply) {
            $this->info('Dry run — re-run with --apply to write.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($fixes, $rows): void {
            foreach ($fixes as $id => $columns) {
                /** @var DictionaryWord $word */
                $word = $rows->get($id);
                foreach ($columns as $col => [, $new]) {
                    $word->{$col} = $new;
                }
                $oldSlug = (string) $word->slug;
                $newSlug = DictionaryWord::makeHeadwordSlug($word->iast, $word->cyrillic, $word->devanagari) ?: null;
                if ($newSlug !== null && $newSlug !== $oldSlug) {
                    $word->slug = $newSlug;
                    $this->line("APPLY id {$id} slug: «{$oldSlug}» → «{$newSlug}» (old /slovar URL retires)");
                }
                $word->save();
            }
        });

        $this->info('Done: '.count($fixes).' row(s) repaired.');

        return self::SUCCESS;
    }
}
