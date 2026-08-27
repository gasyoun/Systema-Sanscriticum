<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

/**
 * Строка каталога документации продукта (H3243).
 * Путь markdown — только эта колонка, никогда из HTTP.
 *
 * @property int $id
 * @property string $slug
 * @property string $title
 * @property string|null $description
 * @property string $audience
 * @property string|null $route_name
 * @property string|null $url_path
 * @property string|null $faq_fragment
 * @property string|null $source_path
 * @property string|null $quiz_audience
 * @property string|null $access_gate
 * @property int $sort_order
 * @property bool $is_active
 * @property bool $is_seeded
 */
class ProductDoc extends Model
{
    use HasFactory;

    public const AUDIENCES = [
        'student' => 'Ученик',
        'teacher' => 'Преподаватель',
        'curator' => 'Куратор',
        'accountant' => 'Бухгалтер',
        'ops' => 'Операции',
    ];

    public const SCREENSHOT_DIRS = [
        'docs/STUDENT_CABINET_GUIDE_RU.md' => 'docs/screenshots/student-guide',
        'docs/TEACHER_CABINET_GUIDE_RU.md' => 'docs/screenshots/teacher-guide',
        'docs/CURATOR_ADMIN_GUIDE_RU.md' => 'docs/screenshots/curator-guide',
    ];

    protected $fillable = [
        'slug',
        'title',
        'description',
        'audience',
        'route_name',
        'url_path',
        'faq_fragment',
        'source_path',
        'quiz_audience',
        'access_gate',
        'sort_order',
        'is_active',
        'is_seeded',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_seeded' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $doc): void {
            $doc->source_path = self::normalizeSourcePath($doc->source_path);
        });
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function audienceLabel(): string
    {
        return self::AUDIENCES[$this->audience] ?? $this->audience;
    }

    public function href(): string
    {
        if (is_string($this->route_name) && $this->route_name !== '' && Route::has($this->route_name)) {
            return route($this->route_name);
        }

        if (is_string($this->url_path) && $this->url_path !== '') {
            return $this->url_path;
        }

        return '#';
    }

    public function faqHref(): ?string
    {
        if (! is_string($this->faq_fragment) || $this->faq_fragment === '') {
            return null;
        }

        $base = $this->href();
        if ($base === '#') {
            return null;
        }

        return $base.'#'.$this->faq_fragment;
    }

    public function quizHref(): ?string
    {
        return match ($this->quiz_audience) {
            'student' => Route::has('student.cabinet-mastery')
                ? route('student.cabinet-mastery')
                : '/dvaram/proverka',
            'curator' => Route::has('filament.admin.pages.cabinet-mastery')
                ? route('filament.admin.pages.cabinet-mastery')
                : '/admin/cabinet-mastery',
            default => null,
        };
    }

    /**
     * Относительный docs/*.md внутри base_path('docs'), иначе null (пусто)
     * или ValidationException (побег из каталога).
     */
    public static function normalizeSourcePath(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        $relative = ltrim(str_replace('\\', '/', trim($path)), '/');

        if (str_contains($relative, '..')
            || ! str_starts_with($relative, 'docs/')
            || ! str_ends_with(mb_strtolower($relative), '.md')) {
            throw ValidationException::withMessages([
                'source_path' => 'Путь markdown только docs/*.md, без ..',
            ]);
        }

        $docsRoot = realpath(base_path('docs'));
        $candidate = base_path($relative);
        $resolved = realpath($candidate);

        if ($docsRoot === false) {
            throw ValidationException::withMessages([
                'source_path' => 'Каталог docs недоступен.',
            ]);
        }

        if ($resolved === false) {
            $parent = realpath(dirname($candidate));
            if ($parent === false || ! str_starts_with($parent, $docsRoot)) {
                throw ValidationException::withMessages([
                    'source_path' => 'Путь markdown только внутри docs/.',
                ]);
            }

            return $relative;
        }

        if (! str_starts_with($resolved, $docsRoot)) {
            throw ValidationException::withMessages([
                'source_path' => 'Путь markdown только внутри docs/.',
            ]);
        }

        return $relative;
    }

    public static function assertSafeSourcePath(?string $path): ?string
    {
        try {
            $relative = self::normalizeSourcePath($path);
        } catch (ValidationException) {
            return null;
        }

        if ($relative === null) {
            return null;
        }

        $full = realpath(base_path($relative));
        $docsRoot = realpath(base_path('docs'));

        if ($full === false || $docsRoot === false || ! str_starts_with($full, $docsRoot) || ! is_file($full)) {
            return null;
        }

        return $full;
    }

    /**
     * @return array{have: int, mentioned: int}|null
     */
    public function screenshotCoverage(): ?array
    {
        $dir = self::SCREENSHOT_DIRS[$this->source_path ?? ''] ?? null;
        $absMd = self::assertSafeSourcePath($this->source_path);
        if ($dir === null || $absMd === null) {
            return null;
        }

        $absDir = base_path($dir);
        if (! is_dir($absDir)) {
            return ['have' => 0, 'mentioned' => 0];
        }

        $pngs = glob($absDir.DIRECTORY_SEPARATOR.'*.png') ?: [];
        $markdown = (string) file_get_contents($absMd);
        preg_match_all('/!\[[^\]]*]\([^)]+\)/', $markdown, $matches);

        return [
            'have' => count($pngs),
            'mentioned' => count($matches[0]),
        ];
    }
}
