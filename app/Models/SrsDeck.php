<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Колода SRS. Владелец user_id NULL = системная колода. См. миграцию srs_decks (H211).
 *
 * H7966 (трек А): таксономия — topic / difficulty / size_tier / tags — и
 * иерархия сегментации мега-деков через parent_deck_id (подколоды топ-500 /
 * 500-2000 / остальное). Вокабуляры осей — константы ниже: фильтры хабов и
 * Filament опираются на них, офлайн-сидder и утвердительный проход учителя
 * пишут только в эти значения.
 */
class SrsDeck extends Model
{
    /** Ось «тема»: словарь / корни / письмо / курс / грамматика (офлайн-проход Q9). */
    public const TOPICS = [
        'lexicon' => 'Словарь',
        'roots' => 'Корни',
        'script' => 'Письмо',
        'course' => 'Курс',
        'grammar' => 'Грамматика',
    ];

    /** Ось «сложность»: заявленная учителем, не вычисляется. */
    public const DIFFICULTIES = [
        'beginner' => 'Начальный',
        'intermediate' => 'Средний',
        'advanced' => 'Продвинутый',
    ];

    /**
     * Ось «размер»: считается из cards_count (SrsTaxonomySeeder /
     * MegaDeckSegmenter), пороги — медианы бейзлайна 10-11-2026
     * (мега-деки ≈11 тыс., остальные ≤570).
     */
    public const SIZE_TIERS = [
        'small' => 'Малая (до 100)',
        'medium' => 'Средняя (100–999)',
        'large' => 'Большая (1000–4999)',
        'mega' => 'Мега (5000+)',
    ];

    protected $fillable = [
        'user_id',
        'course_id',
        'lesson_id',
        'note_type_id',
        'name',
        'slug',
        'language',
        'visibility',
        'description',
        'topic',
        'difficulty',
        'size_tier',
        'tags',
        'parent_deck_id',
    ];

    protected $casts = [
        'tags' => 'array',
    ];

    public function noteType(): BelongsTo
    {
        return $this->belongsTo(SrsNoteType::class, 'note_type_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_deck_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_deck_id');
    }

    /** Размерная tier по количеству карточек — источник истины для size_tier. */
    public static function sizeTierFor(int $cardsCount): string
    {
        return match (true) {
            $cardsCount >= 5000 => 'mega',
            $cardsCount >= 1000 => 'large',
            $cardsCount >= 100 => 'medium',
            default => 'small',
        };
    }

    /** Русская подпись topic для хабов/Filament; null → пустая строка. */
    public function topicLabel(): string
    {
        return self::TOPICS[(string) $this->topic] ?? '';
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class, 'lesson_id');
    }

    public function cards(): HasMany
    {
        return $this->hasMany(SrsCard::class, 'deck_id');
    }

    public function subscribers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'srs_deck_user', 'deck_id', 'user_id')
            ->withPivot(['new_per_day', 'direction', 'subscribed_at'])
            ->withTimestamps();
    }

    /**
     * SEO-META-1: public deck pages are near-identical trial shells (~390 words), so
     * only decks that stand for a whole deck get indexed. Generic "Level N"
     * rows (N > 1) duplicate level 1 and stay out of the sitemap until they
     * carry distinct content.
     */
    public function isSeoIndexable(): bool
    {
        $slug = trim((string) $this->slug);
        if ($slug === '' || str_starts_with($slug, 'id-')) {
            return false;
        }

        if (preg_match('/^level\s+(\d+)$/iu', trim((string) $this->name), $matches) === 1) {
            return (int) $matches[1] === 1;
        }

        return true;
    }

    public function seoTitle(): string
    {
        // SeoTitle strips the brand and caps the result at 60 chars. Budget the
        // quoted name here so truncation never leaves an unbalanced «…».
        $name = trim((string) $this->name);
        $stripped = trim((string) preg_replace('/\s*\([^)]*\)\s*$/u', '', $name));
        if ($stripped !== '') {
            $name = $stripped;
        }

        return 'Колода «'.Str::limit($name, 43, '…').'»';
    }

    public function seoDescription(): string
    {
        $description = trim(strip_tags((string) $this->description));
        if ($description === '') {
            $description = 'Публичная колода карточек «'.$this->name.'»: пробный режим без регистрации, интервальное повторение и разбор ошибок.';
        }

        return Str::limit(preg_replace('/\s+/u', ' ', $description) ?? $description, 160);
    }
}
