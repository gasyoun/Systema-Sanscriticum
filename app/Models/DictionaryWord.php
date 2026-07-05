<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DictionaryWord extends Model
{
    use HasFactory;

    protected $fillable = [
        'dictionary_id',
        'devanagari',
        'iast',
        'cyrillic',
        'slug',
        'wikidata_qid',
        'translation',
        'page',
    ];

    /** Автоматически проставляем slug при сохранении, если он пуст (новые/правленые слова). */
    protected static function booted(): void
    {
        static::saving(function (self $word): void {
            if (blank($word->slug)) {
                $word->slug = self::makeHeadwordSlug($word->iast, $word->cyrillic, $word->devanagari) ?: null;
            }
        });
    }

    // Обратная связь: Каждое слово принадлежит какому-то одному словарю
    public function dictionary()
    {
        return $this->belongsTo(Dictionary::class);
    }

    /**
     * Per-HEADWORD url slug (decision D3): built from IAST (ASCII-friendly), falling
     * back to cyrillic then a light devanagari transliteration. Two rows with the same
     * headword yield the same slug, so they collapse onto one canonical /slovar/{slug}.
     */
    public static function makeHeadwordSlug(?string $iast, ?string $cyrillic = null, ?string $devanagari = null): string
    {
        foreach ([$iast, $cyrillic] as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '') {
                $slug = Str::slug($candidate);
                if ($slug !== '') {
                    return $slug;
                }
            }
        }

        // Devanagari has no ASCII slug via Str::slug; last-resort stable key off its codepoints.
        $devanagari = trim((string) $devanagari);
        if ($devanagari !== '') {
            return 'dev-'.substr(md5($devanagari), 0, 12);
        }

        return '';
    }

    /**
     * Best human-readable headword for display / JSON-LD `name`: prefer IAST, then
     * Devanagari, then Cyrillic.
     */
    public function headword(): string
    {
        return trim((string) ($this->iast ?: $this->devanagari ?: $this->cyrillic ?: $this->slug));
    }

    /**
     * Whether THIS page may be indexed. Wave 0 (`index_enabled` = false) → always
     * false. Once enabled, gated by the curated-core quality bar (decisions D1/D2).
     */
    public function isIndexable(): bool
    {
        if (! config('dictionary_seo.index_enabled', false)) {
            return false; // Wave 0 — everything noindex,follow
        }

        $minLen = (int) config('dictionary_seo.gate.min_translation_length', 40);
        if (Str::length(trim((string) $this->translation)) < $minLen) {
            return false;
        }

        // Curated allowlist not yet wired → default-closed so nothing indexes by accident.
        if (config('dictionary_seo.gate.curated_only', true)) {
            return false;
        }

        return true;
    }
}
