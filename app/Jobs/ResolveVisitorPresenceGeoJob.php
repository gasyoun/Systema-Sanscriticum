<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SupportVisitorPresence;
use App\Services\Support\VisitorGeoResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Асинхронно резолвит город присутствующего посетителя (H1197, Jivo-паритет
 * Pillar 2) — тем же {@see VisitorGeoResolver}, что и тред-гео S1 (H1196), чтобы
 * возможный внешний вызов драйвера (ipapi) не блокировал beacon-POST. Пишет в
 * строку присутствия, а не в тред. Идемпотентно: повторный запуск на уже
 * разрешённой строке — no-op.
 */
class ResolveVisitorPresenceGeoJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string,string|null>  $hints  request-time заголовки CF-* из контроллера
     */
    public function __construct(
        public int $presenceId,
        public string $ip,
        public array $hints = [],
    ) {}

    public function handle(VisitorGeoResolver $resolver): void
    {
        $presence = SupportVisitorPresence::find($this->presenceId);

        if (! $presence || $presence->visitor_geo_resolved_at !== null) {
            return; // строка исчезла (вымело) или гео уже разрешено
        }

        $geo = $resolver->resolve($this->ip, $this->hints);

        // Помечаем попытку в любом случае — не резолвим повторно; город может
        // остаться null (приватный IP, драйвер null, промах).
        $presence->forceFill([
            'visitor_city' => $geo['city'] ?? null,
            'visitor_region' => $geo['region'] ?? null,
            'visitor_country' => $geo['country'] ?? null,
            'visitor_geo_resolved_at' => now(),
        ])->save();
    }
}
