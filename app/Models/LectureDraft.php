<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class LectureDraft extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PREPROCESSING = 'preprocessing';

    public const STATUS_EDITING = 'editing';

    public const STATUS_BUILT = 'built';

    public const STATUS_PUBLISHED = 'published';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PREPROCESSING,
        self::STATUS_EDITING,
        self::STATUS_BUILT,
        self::STATUS_PUBLISHED,
    ];

    protected $fillable = [
        'slug',
        'title',
        'status',
        'course_id',
        'lesson_id',
        'created_by',
        'data_json_path',
        'output_html_path',
        'slides_dir',
        'meta',
        'error_log',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $draft) {
            if (empty($draft->slug)) {
                $draft->slug = self::generateUniqueSlug($draft->title ?? 'lecture');
            }
        });
    }

    public static function generateUniqueSlug(string $base): string
    {
        $slug = Str::slug($base) ?: 'lecture';
        $candidate = $slug;
        $i = 2;
        while (self::where('slug', $candidate)->exists()) {
            $candidate = $slug.'-'.$i++;
        }

        return $candidate;
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function workingDir(): string
    {
        return 'lectures/'.$this->id;
    }

    // ==========================================
    // ФОНОВАЯ ОБРАБОТКА (Phase A — async pipeline)
    // ==========================================
    // Длинные шаги (препроцесс, сборка, ИИ) ушли в очередь. Признак «идёт
    // фоновая задача» держим в meta — без миграции и без раздувания enum status.
    // Источник истины для блокировки кнопок и баннера «выполняется».

    /** Идёт ли сейчас фоновая задача по этому черновику. */
    public function isProcessing(): bool
    {
        return ! empty($this->meta['processing']['label'] ?? null);
    }

    /** Человекочитаемая метка текущей фоновой задачи (или null). */
    public function processingLabel(): ?string
    {
        return $this->meta['processing']['label'] ?? null;
    }

    /** Когда стартовала текущая фоновая задача (ISO-строка или null). */
    public function processingStartedAt(): ?string
    {
        return $this->meta['processing']['started_at'] ?? null;
    }

    /** Пометить черновик как «идёт фоновая задача X». Вызывается из экшена перед dispatch. */
    public function markProcessing(string $label): void
    {
        $meta = $this->meta ?? [];
        $meta['processing'] = ['label' => $label, 'started_at' => now()->toIso8601String()];
        $this->forceFill(['meta' => $meta])->save();
    }

    /** Снять признак фоновой задачи. Вызывается джобой в finally (успех или ошибка). */
    public function clearProcessing(): void
    {
        $meta = $this->meta ?? [];
        unset($meta['processing']);
        $this->forceFill(['meta' => $meta])->save();
    }

    /** Сохранить краткий итог последнего прогона ИИ для показа в редакторе. */
    public function recordAiResult(string $summary): void
    {
        $meta = $this->meta ?? [];
        $meta['last_ai'] = ['summary' => $summary, 'at' => now()->toIso8601String()];
        $this->forceFill(['meta' => $meta])->save();
    }

    // ==========================================
    // ADVISORY-ЛОК РЕДАКТИРОВАНИЯ (Phase C)
    // ==========================================
    // Мягкая блокировка: показываем «редактирует X», но не запрещаем жёстко.
    // Хартбит шлёт поллинг-виджет редактора; без хартбита лок «протухает» за TTL,
    // поэтому закрытая вкладка освобождает черновик автоматически.

    /** Сколько секунд лок считается активным без обновления (хартбита). */
    public const LOCK_TTL_SECONDS = 120;

    /** Текущий активный лок (или null, если свободен/протух). */
    public function activeLock(): ?array
    {
        $lock = $this->meta['lock'] ?? null;
        if (! is_array($lock) || empty($lock['at'])) {
            return null;
        }

        $age = now()->diffInSeconds(Carbon::parse($lock['at']), absolute: true);

        return $age <= self::LOCK_TTL_SECONDS ? $lock : null;
    }

    /** Заблокирован ли черновик активно другим пользователем. */
    public function isLockedByOther(int $userId): bool
    {
        $lock = $this->activeLock();

        return $lock !== null && (int) ($lock['user_id'] ?? 0) !== $userId;
    }

    /**
     * Взять/обновить лок. Возвращает true, если черновик теперь за нами
     * (свободен/протух/уже наш), false — если активно держит другой.
     */
    public function heartbeatLock(int $userId, string $userName): bool
    {
        if ($this->isLockedByOther($userId)) {
            return false;
        }

        $meta = $this->meta ?? [];
        $meta['lock'] = ['user_id' => $userId, 'name' => $userName, 'at' => now()->toIso8601String()];
        $this->forceFill(['meta' => $meta])->save();

        return true;
    }

    /** Снять свой лок (на чужой не влияет). */
    public function releaseLock(int $userId): void
    {
        $lock = $this->meta['lock'] ?? null;
        if (is_array($lock) && (int) ($lock['user_id'] ?? 0) === $userId) {
            $meta = $this->meta ?? [];
            unset($meta['lock']);
            $this->forceFill(['meta' => $meta])->save();
        }
    }
}
