<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ProductDoc;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Поиск по каталогу: поля таблицы, затем заголовки и FAQ внутри docs/*.md.
 * Вызывающий уже на adminOnly-странице — роли студента здесь не режем.
 */
final class ProductDocSearch
{
    /**
     * @return Collection<int, array{doc: ProductDoc, field: string, heading: string, href: string}>
     */
    public static function search(?User $user, string $q): Collection
    {
        unset($user);

        $needle = trim($q);
        if (mb_strlen($needle) < 2) {
            return collect();
        }

        $hits = collect();
        $lower = mb_strtolower($needle);

        $docs = ProductDoc::query()->active()->orderBy('sort_order')->orderBy('title')->get();

        foreach ($docs as $doc) {
            foreach (['title', 'description', 'audience', 'slug'] as $field) {
                $value = (string) ($doc->{$field} ?? '');
                if ($value !== '' && mb_stripos($value, $needle) !== false) {
                    $hits->push([
                        'doc' => $doc,
                        'field' => $field,
                        'heading' => $doc->title,
                        'href' => $doc->href(),
                    ]);
                    break;
                }
            }

            $abs = ProductDoc::assertSafeSourcePath($doc->source_path);
            if ($abs === null) {
                continue;
            }

            $markdown = (string) file_get_contents($abs);
            foreach (self::headingHits($markdown, $lower, $doc) as $hit) {
                $hits->push($hit);
            }
        }

        return $hits->unique(fn (array $hit): string => $hit['doc']->id.'|'.$hit['href'].'|'.$hit['heading'])->values();
    }

    /**
     * @return list<array{doc: ProductDoc, field: string, heading: string, href: string}>
     */
    private static function headingHits(string $markdown, string $lowerNeedle, ProductDoc $doc): array
    {
        $hits = [];
        $lines = preg_split("/\R/u", $markdown) ?: [];
        $inFaq = false;

        foreach ($lines as $line) {
            if (preg_match('/^(#{1,3})\s+(.+)$/u', $line, $m) !== 1) {
                if ($inFaq && mb_stripos(mb_strtolower($line), $lowerNeedle) !== false) {
                    $hits[] = [
                        'doc' => $doc,
                        'field' => 'faq',
                        'heading' => $doc->title.' · FAQ',
                        'href' => $doc->faqHref() ?? $doc->href(),
                    ];
                    $inFaq = false;
                }

                continue;
            }

            $heading = trim($m[2]);
            $id = self::headingId($heading);
            $isFaqHeading = (bool) preg_match('/часть\s+iv|частые вопросы/iu', $heading);
            $inFaq = $isFaqHeading;

            if (mb_stripos(mb_strtolower($heading), $lowerNeedle) !== false) {
                $base = $doc->href();
                $hits[] = [
                    'doc' => $doc,
                    'field' => $isFaqHeading ? 'faq' : 'heading',
                    'heading' => $heading,
                    'href' => $base === '#' ? $base : $base.'#'.$id,
                ];
            }
        }

        return $hits;
    }

    public static function headingId(string $heading): string
    {
        $t = mb_strtolower(trim($heading));
        $t = preg_replace('/[^\p{L}\p{N}\s-]+/u', '', $t) ?? $t;
        $t = preg_replace('/\s+/u', '-', trim($t)) ?? $t;

        return $t;
    }
}
