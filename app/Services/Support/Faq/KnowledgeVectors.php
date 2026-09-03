<?php

declare(strict_types=1);

namespace App\Services\Support\Faq;

/**
 * H4001 — единственное место, где знают бинарный формат knowledge_chunks:
 * float32 little-endian, один чанк = 4*dims байт. Плюс sha256 content-hash
 * (модель + размерность + текст чанка) и косинус для dense-ноги.
 */
final class KnowledgeVectors
{
    /** @param  list<float>  $vector */
    public static function pack(array $vector): string
    {
        $packed = pack('g*', ...array_values($vector));
        if ($packed === false) {
            throw new \RuntimeException('knowledge: failed to pack vector');
        }

        return $packed;
    }

    /** @return list<float> */
    public static function unpack(string $binary): array
    {
        if ($binary === '') {
            return [];
        }

        /** @var list<float|false>|false $unpacked */
        $unpacked = unpack('g*', $binary);
        if ($unpacked === false) {
            throw new \RuntimeException('knowledge: failed to unpack vector');
        }

        return array_values(array_map('floatval', $unpacked));
    }

    public static function contentHash(string $model, int $dims, string $text): string
    {
        return hash('sha256', $model.'|'.$dims.'|'.$text);
    }

    /**
     * Косинус двух векторов равной длины; нулевой вектор → 0.0 (не совпадение).
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public static function cosine(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] * $a[$i];
            $nb += $b[$i] * $b[$i];
        }
        if ($na === 0.0 || $nb === 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($na) * sqrt($nb));
    }
}
