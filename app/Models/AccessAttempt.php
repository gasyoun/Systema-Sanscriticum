<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Одна запись «проблемы со входом» (H849): неудачный логин либо запрос ссылки
 * восстановления. Пишется из слушателей auth-событий и из PasswordResetController.
 * Разблокировка проставляет handled_* — так лента отделяет «разобранных» от
 * «застрявших».
 */
class AccessAttempt extends Model
{
    use HasFactory;

    /** Неверный email/пароль на /login или /shop/login (событие Failed). */
    public const KIND_FAILED_LOGIN = 'failed_login';

    /** Сработал rate-limit (событие Lockout) — «слишком много попыток». */
    public const KIND_LOCKOUT = 'lockout';

    /** Запросили восстановление, ссылка отправлена (по факту письмо ушло в мейлер). */
    public const KIND_RESET_SENT = 'reset_sent';

    /** Запросили восстановление, но email в базе не найден. */
    public const KIND_RESET_NOT_FOUND = 'reset_not_found';

    /** Запросили восстановление, но брокер вернул троттл (ссылку уже слали <60с). */
    public const KIND_RESET_THROTTLED = 'reset_throttled';

    protected $fillable = [
        'user_id',
        'email',
        'kind',
        'reason',
        'ip',
        'user_agent',
        'handled_at',
        'handled_by_user_id',
        'handled_action',
    ];

    protected $casts = [
        'handled_at' => 'datetime',
    ];

    /** @return array<string,string> [kind => человекочитаемая метка] */
    public static function kindLabels(): array
    {
        return [
            self::KIND_FAILED_LOGIN => 'Неверный пароль',
            self::KIND_LOCKOUT => 'Слишком много попыток',
            self::KIND_RESET_SENT => 'Запрошена ссылка (отправлена)',
            self::KIND_RESET_NOT_FOUND => 'Восстановление: email не найден',
            self::KIND_RESET_THROTTLED => 'Восстановление: троттл',
        ];
    }

    public function kindLabel(): string
    {
        return self::kindLabels()[$this->kind] ?? $this->kind;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by_user_id');
    }

    public function scopeUnhandled(Builder $query): Builder
    {
        return $query->whereNull('handled_at');
    }

    public function scopeRecent(Builder $query, int $days = 14): Builder
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}
