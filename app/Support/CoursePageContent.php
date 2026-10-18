<?php

declare(strict_types=1);

namespace App\Support;

/**
 * H6580: контент страницы /k/<slug>, перенесённый из T1-анонса канала —
 * фиксированная таблица «элемент поста → поле страницы» (schema-комментарий
 * в resources/data/course-page-content.json, канон таблицы —
 * docs/COURSE_PAGE_ANONS_MAPPING_2026-10-13.md). Паттерн H6570
 * CourseHeroSchedule: контент-JSON по slug, без БД. description_html
 * санитизируется тем же RichHtml, что и course->description_html —
 * до H2896 #8 HTML не доходит. Числа (цена, кворум, расписание) постом
 * не переносятся: канон живёт на странице, пост зеркалит снапшот.
 */
class CoursePageContent
{
    /** @return array{description_html:?string,program_bullets:array<string>,video_links:array<string,string>,prerequisites:?string,faqs:array<array{q:string,a:string}>}|null */
    public static function for(string $slug): ?array
    {
        $file = resource_path('data/course-page-content.json');
        if (! is_file($file)) {
            return null;
        }

        $all = json_decode((string) file_get_contents($file), true);
        $entry = is_array($all) ? ($all[$slug] ?? null) : null;
        if (! is_array($entry)) {
            return null;
        }

        $description = isset($entry['description_html']) && $entry['description_html'] !== ''
            ? RichHtml::sanitize((string) $entry['description_html'])
            : null;

        $videoLinks = [];
        foreach ((array) ($entry['video_links'] ?? []) as $platform => $url) {
            if (is_string($url) && $url !== '') {
                $videoLinks[(string) $platform] = $url;
            }
        }

        $faqs = [];
        foreach ((array) ($entry['faqs'] ?? []) as $faq) {
            if (is_array($faq) && filled($faq['q'] ?? null) && filled($faq['a'] ?? null)) {
                $faqs[] = ['q' => (string) $faq['q'], 'a' => (string) $faq['a']];
            }
        }

        return [
            'description_html' => $description,
            'program_bullets' => array_values(array_filter(
                array_map('strval', (array) ($entry['program_bullets'] ?? [])),
                fn (string $bullet) => trim($bullet) !== '',
            )),
            'video_links' => $videoLinks,
            'prerequisites' => isset($entry['prerequisites']) && filled($entry['prerequisites'])
                ? (string) $entry['prerequisites']
                : null,
            'faqs' => $faqs,
        ];
    }
}
