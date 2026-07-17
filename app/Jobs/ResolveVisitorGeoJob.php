<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SupportConversation;
use App\Services\Support\VisitorGeoResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Асинхронно резолвит гео посетителя веб-чата и дописывает город/регион/страну
 * в его тред (H1196, Jivo-паритет Pillar 1). Вынесено из PublicChatController,
 * чтобы возможный внешний вызов драйвера (ipapi) не блокировал POST /chat/message.
 * Идемпотентно: повторный запуск на уже разрешённом треде — no-op.
 */
class ResolveVisitorGeoJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string,string|null>  $hints  request-time заголовки CF-* из контроллера
     */
    public function __construct(
        public int $conversationId,
        public string $ip,
        public array $hints = [],
    ) {}

    public function handle(VisitorGeoResolver $resolver): void
    {
        $thread = SupportConversation::find($this->conversationId);

        if (! $thread || $thread->visitor_geo_resolved_at !== null) {
            return; // тред исчез или гео уже разрешено
        }

        $geo = $resolver->resolve($this->ip, $this->hints);

        // Всегда помечаем попытку (visitor_geo_resolved_at), чтобы не резолвить
        // повторно; город может остаться null (приватный IP, драйвер null, промах).
        $thread->forceFill([
            'visitor_city' => $geo['city'] ?? null,
            'visitor_region' => $geo['region'] ?? null,
            'visitor_country' => $geo['country'] ?? null,
            'visitor_geo_resolved_at' => now(),
        ])->save();
    }
}
