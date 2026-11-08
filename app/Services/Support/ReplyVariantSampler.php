<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\MessageTemplate;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * H6767: выбор варианта ответа из одобренного пула (флаг
 * features.support_reply_variant_sampling, default OFF).
 *
 * Зачем: юзербот, повторяющий байт-в-байт один и тот же исходящий текст
 * во многих чатах — паттерн, который ловят анти-спам эвристики MTProto,
 * и просто роботный тон. Пул — выверенные парафразы (одобрение человека),
 * выбор — равномерный, в момент отправки.
 *
 * RNG инъекцируется в конструктор: в тестах — сеемый Mt19937, в проде —
 * незасеянный Randomizer (энтропия ОС). Провенанс варианта пишется
 * в meta dm_auto_sent (id/размер пула — НИКОГДА тексты, см. privacy-забор
 * handoff H6767).
 */
final class ReplyVariantSampler
{
    /** Ack-вариант по умолчанию: единственный auto_ack_text (fallback-пул). */
    public const ACK_FALLBACK_VARIANT_ID = 0;

    public function __construct(private readonly Randomizer $randomizer = new Randomizer) {}

    /**
     * Равномерный выбор из пула строк; пустой пул — fallback-текст как
     * единственный вариант (вариант 0 из пула размера 1).
     *
     * @param  list<string>  $pool
     * @return array{text: string, variant_id: int, pool_size: int}
     */
    public function pickText(array $pool, string $fallback): array
    {
        $pool = array_values(array_filter(
            array_map(static fn ($text): string => (string) $text, $pool),
            static fn (string $text): bool => trim($text) !== '',
        ));

        if ($pool === []) {
            return ['text' => $fallback, 'variant_id' => self::ACK_FALLBACK_VARIANT_ID, 'pool_size' => 1];
        }

        $index = $this->randomIndex(count($pool));

        return ['text' => $pool[$index], 'variant_id' => $index, 'pool_size' => count($pool)];
    }

    /**
     * Равномерный выбор шаблона из ВСЕХ строк, привязанных к категории.
     *
     * ОСОЗНАННОЕ ИЗМЕНЕНИЕ к прежнему «последний updated_at выигрывает»:
     * при включённом флаге пул — все подходящие строки, и каждая может
     * уйти студенту. Задокументировано в policy/sufler.policy.yml
     * (блок sampling) и в handoff H6767.
     */
    public function pickTemplate(iterable $templates): ?MessageTemplate
    {
        $rows = $templates instanceof \Traversable ? iterator_to_array($templates) : $templates;

        if ($rows === []) {
            return null;
        }

        $rows = array_values($rows);

        return $rows[$this->randomIndex(count($rows))];
    }

    private function randomIndex(int $poolSize): int
    {
        return $this->randomizer->getInt(0, $poolSize - 1);
    }

    /**
     * Сеемый движок для тестов: детерминированная последовательность выбора.
     */
    public static function seeded(int $seed): self
    {
        return new self(new Randomizer(new Mt19937($seed)));
    }
}
