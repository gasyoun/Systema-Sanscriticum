<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Профиль одного курса из того же агрегата (H4832): сколько глоссария
 * преподавалось на курсе и какие термины — самые частые.
 */
class TeachingGlossaryCourse extends Model
{
    protected $fillable = [
        'course',
        'n_headwords',
        'top_terms',
    ];

    protected $casts = [
        'n_headwords' => 'integer',
    ];
}
