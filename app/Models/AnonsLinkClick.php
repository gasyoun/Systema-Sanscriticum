<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * H5049 R6: счётчик кликов по /ga/ коротким ссылкам. БЕЗ персональных
 * данных — только слаг, UTM-кортеж и время.
 *
 * @property string $link
 * @property string|null $publication_key
 * @property array<string, string>|null $utm
 */
class AnonsLinkClick extends Model
{
    protected $fillable = ['link', 'publication_key', 'utm', 'clicked_at'];

    protected $casts = [
        'utm' => 'array',
        'clicked_at' => 'datetime',
    ];

    /** /ga/m26-rusamskrtam-st-cred-20260917-01 → "m26-rusamskrtam-st-cred-20260917-01". */
    public static function slugFrom(string $path): ?string
    {
        return preg_match('~^ga/([a-z0-9-]+)$~i', trim($path, '/'), $m) === 1
            ? $m[1]
            : null;
    }
}
