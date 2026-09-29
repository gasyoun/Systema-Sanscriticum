<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Одна строка преподавательского глоссария (H4832): кириллическая форма,
 * встречавшаяся в живой преподавательской речи ≥ 20 раз, её SLP1 лемма(ы)
 * и профиль употребления.
 *
 * Единственный писатель — `teaching-glossary:import`; поверхность читает.
 */
class TeachingGlossaryTerm extends Model
{
    protected $fillable = [
        'cyrillic_form',
        'lemma_slp1',
        'ru_gloss',
        'corpus_freq',
        'n_files',
        'n_courses',
        'ambiguous_lemmas',
        'gloss_provenance',
    ];

    protected $casts = [
        'corpus_freq' => 'integer',
        'n_files' => 'integer',
        'n_courses' => 'integer',
        'ambiguous_lemmas' => 'boolean',
    ];
}
