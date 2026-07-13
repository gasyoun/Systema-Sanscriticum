<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Одноразовый magic-link токен беспарольного входа в кабинет (H324).
 *
 * Дизайн повторяет гарантии сброса пароля: plaintext-токен уходит в письмо, а в
 * БД хранится только его SHA-256 hash. Токен живёт короткое время (TTL) и
 * гасится при первом успешном использовании. Ответы наружу не различают
 * «нет/протух/использован» — всегда 404, чтобы не давать enumeration.
 */
class MagicLinkToken extends Model
{
    use HasFactory;

    /** Время жизни ссылки по умолчанию (минуты). */
    public const DEFAULT_TTL_MINUTES = 60 * 24; // 24 часа

    protected $fillable = [
        'user_id',
        'token_hash',
        'purpose',
        'expires_at',
        'consumed_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Выпускает новый токен для пользователя и возвращает PLAINTEXT-строку для
     * письма. В БД оседает только hash — plaintext здесь единственное место, где
     * он существует.
     */
    public static function issueFor(User $user, string $purpose = 'newsletter', ?int $ttlMinutes = null): string
    {
        $plaintext = Str::random(64);

        self::create([
            'user_id' => $user->id,
            'token_hash' => self::hash($plaintext),
            'purpose' => $purpose,
            'expires_at' => now()->addMinutes($ttlMinutes ?? self::DEFAULT_TTL_MINUTES),
        ]);

        return $plaintext;
    }

    /**
     * Ищет ЖИВОЙ (не протухший, не использованный) токен по plaintext.
     * Сравнение идёт по hash — константное по времени за счёт индексного поиска
     * ровно одной строки (unique token_hash) + равенства хешей.
     */
    public static function findActive(string $plaintext, ?string $purpose = null): ?self
    {
        if ($plaintext === '') {
            return null;
        }

        $token = self::where('token_hash', self::hash($plaintext))->first();

        if ($token === null || ! $token->isActive()) {
            return null;
        }

        // Токены с явным назначением (например admin_unblock) гасятся только на
        // «своём» маршруте — чтобы админ-ссылку нельзя было скормить в
        // newsletter-обработчик и наоборот.
        if ($purpose !== null && $token->purpose !== $purpose) {
            return null;
        }

        return $token;
    }

    public function isActive(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }

    /**
     * Помечает токен использованным. Возвращает false, если его уже погасили
     * (защита от гонки/replay при двойном клике).
     */
    public function consume(): bool
    {
        $affected = self::where('id', $this->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        return $affected === 1;
    }

    private static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
