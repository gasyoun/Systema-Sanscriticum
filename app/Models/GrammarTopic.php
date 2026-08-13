<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GrammarTopic extends Model
{
    protected $fillable = [
        'topic_id',
        'slug',
        'status',
        'title_ru',
        'cluster',
        'difficulty',
        'summary_ru',
        'alignment_note',
        'aliases',
        'prerequisites',
        'dictionary_evidence',
        'corpus_evidence',
        'exercise_ids',
        'provenance',
        'payload',
        'content_hash',
        'import_id',
        'retired_at',
    ];

    protected $casts = [
        'aliases' => 'array',
        'prerequisites' => 'array',
        'dictionary_evidence' => 'array',
        'corpus_evidence' => 'array',
        'exercise_ids' => 'array',
        'provenance' => 'array',
        'payload' => 'array',
        'retired_at' => 'datetime',
    ];

    public function sources(): HasMany
    {
        return $this->hasMany(GrammarTopicSource::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(GrammarTopicVersion::class);
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function whitneySources()
    {
        return $this->sources()->where('family', 'whitney');
    }

    public function zalizniakSources()
    {
        return $this->sources()->where('family', 'zalizniak');
    }
}
