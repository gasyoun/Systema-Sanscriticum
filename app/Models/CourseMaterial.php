<?php

namespace App\Models;

use App\Support\MaterialUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Материал библиотеки курса — фаза 1: только ссылка, файл лежит не у нас.
 *
 * Почему отдельная таблица, а не lessons.attachments: тот столбец — массив
 * ОТНОСИТЕЛЬНЫХ путей на диске public, и CourseMaterialsArchiver молча
 * пропускает всё, что не проходит is_file(). URL, положенный туда, исчез бы
 * из ZIP-архива курса без единой ошибки в логах.
 *
 * @property int $id
 * @property int $course_id
 * @property int|null $lesson_id
 * @property string $title
 * @property string|null $author
 * @property string|null $year
 * @property string $source_url
 * @property string|null $download_url
 * @property string|null $host
 * @property string $kind
 * @property string|null $note
 * @property int $sort_order
 * @property bool $is_visible
 * @property int|null $added_by_user_id
 */
class CourseMaterial extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'lesson_id',
        'title',
        'author',
        'year',
        'source_url',
        'download_url',
        'host',
        'kind',
        'note',
        'sort_order',
        'is_visible',
        'added_by_user_id',
    ];

    protected $casts = [
        'is_visible' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Типы материалов и их подписи для студента.
     *
     * ⚠️ Ключи обязаны совпадать со значениями MaterialUrl::KIND_BY_EXT
     * (плюс 'page' — тип по умолчанию). Расходятся — студент увидит сырой
     * ключ вместо подписи.
     */
    public const KINDS = [
        'page' => 'Страница',
        'pdf' => 'PDF / книга',
        'audio' => 'Аудио',
        'video' => 'Видео',
        'archive' => 'Архив',
    ];

    /**
     * Нормализация живёт в модели, а не в форме: так форма Filament, сиды,
     * тесты и будущий разбор сообщений преподавателя не могут разойтись в том,
     * какой download_url считается правильным.
     */
    protected static function booted(): void
    {
        static::creating(function (self $material): void {
            $material->applyUrlDerivations();
        });

        static::updating(function (self $material): void {
            // Пересчитываем только когда сам URL изменился — иначе ручная
            // правка download_url куратором затиралась бы при каждом save().
            if ($material->isDirty('source_url')) {
                $material->applyUrlDerivations();
            }
        });
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_visible', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeShelfOrder(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('title');
    }

    /**
     * Ссылка для студента: прямая, если её удалось вывести, иначе исходная.
     */
    public function effectiveUrl(): string
    {
        return $this->download_url ?: $this->source_url;
    }

    /**
     * В фазе 1 все материалы внешние. Метод существует, чтобы вьюха и архиватор
     * спрашивали модель, а не проверяли пустоту столбца: в фазе 2 (опциональное
     * зеркалирование) здесь появится реальная развилка.
     */
    public function isExternal(): bool
    {
        return true;
    }

    /**
     * Вывести host / download_url / kind / title из source_url.
     */
    protected function applyUrlDerivations(): void
    {
        $this->host = MaterialUrl::host($this->source_url);
        $this->download_url = MaterialUrl::downloadUrl($this->source_url);

        if (blank($this->kind)) {
            $this->kind = MaterialUrl::guessKind($this->source_url);
        }

        if (blank($this->title)) {
            $this->title = MaterialUrl::guessTitle($this->source_url) ?? 'Без названия';
        }
    }
}
