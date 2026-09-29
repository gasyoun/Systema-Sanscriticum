<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * H5049 R8: собственный конечный автомат КАЖДОГО пункта назначения.
 * Падение одного аккаунта не дублирует и не блокирует остальные;
 * постоянные ошибки валидации — fail-closed (blocked), транзиентные —
 * bounded-backoff retry (failed + next_retry_at).
 *
 * @property string $destination
 * @property string $platform
 * @property string $account
 * @property int $frame_index
 * @property string $state
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon|null $next_retry_at
 * @property array<string, mixed>|null $remote_ids
 * @property string|null $content_hash
 * @property string|null $artifact_path
 * @property string|null $short_link
 * @property array<string, string>|null $utm
 */
class AnonsDestinationRun extends Model
{
    public const STATE_PENDING = 'pending';

    public const STATE_RUNNING = 'running';

    public const STATE_PUBLISHED = 'published';

    public const STATE_FAILED = 'failed';

    public const STATE_BLOCKED = 'blocked';

    protected $fillable = [
        'anons_publication_id', 'destination', 'platform', 'account',
        'frame_index', 'state', 'attempts', 'last_error', 'next_retry_at',
        'remote_ids', 'content_hash', 'artifact_path', 'short_link', 'utm',
    ];

    protected $casts = [
        'frame_index' => 'integer',
        'attempts' => 'integer',
        'next_retry_at' => 'datetime',
        'remote_ids' => 'array',
        'utm' => 'array',
    ];

    /** Транзиентная ошибка — можно повторить по backoff. */
    public function isRetryable(): bool
    {
        return $this->state === self::STATE_FAILED;
    }
}
