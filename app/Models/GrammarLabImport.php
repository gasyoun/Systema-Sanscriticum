<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GrammarLabImport extends Model
{
    protected $fillable = [
        'bundle_version',
        'schema_version',
        'manifest_sha256',
        'bundle_sha256',
        'published_topic_count',
        'upserted_count',
        'unchanged_count',
        'retired_count',
        'pin_tag',
        'imported_at',
    ];

    protected $casts = [
        'imported_at' => 'datetime',
    ];
}
