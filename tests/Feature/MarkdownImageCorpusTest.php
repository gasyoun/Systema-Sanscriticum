<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Репозиторный пин на разметку картинок и CTA-ссылок в закоммиченных `.md`.
 *
 * Механическая правка ссылок «относительная ссылка в закоммиченном .md →
 * полная blob-ссылка» живёт ВНЕ репозитория и уже дважды роняла `main`:
 * `5ad0bad1` развернула 18 кадров в blob-адреса (H4279), `319659f8` вырезала
 * разметку картинок целиком вместе с CTA-ссылками писем (H4316). Оба раза
 * красноту ловили приёмки гидов — по числу кадров, случайно. Здесь пин стоит
 * на самой разметке, поэтому падает сразу и называет причину.
 *
 * Три формы порчи, которые ловит этот класс:
 *   1. картинка исчезла (пол по числу картинок в файле);
 *   2. источник картинки стал blob-адресом (blob в `<img>` отдаёт HTML, не PNG);
 *   3. CTA-ссылка письма с плейсхолдером-целью схлопнулась в жирный текст.
 */
class MarkdownImageCorpusTest extends TestCase
{
    /**
     * Пол по числу картинок: файл → минимум `![…](…)`.
     *
     * Пол, а не точное число: добавлять кадры можно свободно, вырезать — нет.
     * Новый иллюстрированный документ добавляется сюда осознанно.
     */
    private const IMAGE_FLOORS = [
        'docs/TEACHER_CABINET_GUIDE_RU.md' => 15,
        'docs/STUDENT_CABINET_GUIDE_RU.md' => 14,
        'docs/materials/pwg-arzamas/SOURCE.md' => 9,
        'docs/ACCOUNTANT_CABINET_GUIDE_RU.md' => 7,
        'docs/CURATOR_ADMIN_GUIDE_RU.md' => 6,
        'docs/materials/kossovich-arzamas/SOURCE.md' => 4,
    ];

    /**
     * Письма маркетинга: ссылка с плейсхолдером-целью (`{link}`) — не «битая
     * ссылка», а шаблон, который подставляет рассыльщик. Правка ссылок её не
     * узнаёт и вырезает, оставляя жирный текст без перехода.
     */
    private const PLACEHOLDER_LINK_FLOORS = [
        'marketing/marathon-2026-08/marathon-email-sequence.md' => 5,
    ];

    public function test_illustrated_docs_keep_their_images(): void
    {
        foreach (self::IMAGE_FLOORS as $relative => $floor) {
            $path = base_path($relative);
            $this->assertFileExists($path, "Иллюстрированный документ пропал: {$relative}");

            preg_match_all('#!\[[^\]]*\]\([^)]+\)#u', (string) file_get_contents($path), $matches);

            $this->assertGreaterThanOrEqual(
                $floor,
                count($matches[0]),
                "В {$relative} осталось ".count($matches[0])." картинок при поле {$floor}. "
                    .'Разметку `![](…)` вырезала механическая правка ссылок — верните её, а не занижайте пол.'
            );
        }
    }

    public function test_no_image_source_is_a_github_blob_url(): void
    {
        $offenders = [];

        foreach ($this->trackedMarkdownFiles() as $relative) {
            preg_match_all(
                '#!\[[^\]]*\]\((https?://[^)]*/blob/[^)]+)\)#u',
                (string) file_get_contents(base_path($relative)),
                $matches
            );

            foreach ($matches[1] as $url) {
                $offenders[] = "{$relative}: {$url}";
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Источник картинки — blob-адрес (GitHub отдаёт по нему HTML, не PNG):\n  "
                .implode("\n  ", $offenders)
        );
    }

    public function test_marketing_emails_keep_placeholder_cta_links(): void
    {
        foreach (self::PLACEHOLDER_LINK_FLOORS as $relative => $floor) {
            $path = base_path($relative);
            $this->assertFileExists($path, "Письмо пропало: {$relative}");

            preg_match_all('#\[[^\]]+\]\(\{[a-z_]+\}\)#u', (string) file_get_contents($path), $matches);

            $this->assertGreaterThanOrEqual(
                $floor,
                count($matches[0]),
                "В {$relative} осталось ".count($matches[0])." CTA-ссылок с плейсхолдером при поле {$floor}. "
                    .'Ссылка вида `[текст]({link})` — шаблон рассыльщика, а не битый адрес.'
            );
        }
    }

    /** @return list<string> */
    private function trackedMarkdownFiles(): array
    {
        $output = [];
        exec('cd '.escapeshellarg(base_path()).' && git ls-files -- "*.md"', $output, $status);

        $this->assertSame(0, $status, 'git ls-files не отработал — пин не может перечислить корпус.');
        $this->assertNotEmpty($output, 'git ls-files не вернул ни одного .md.');

        return array_values(array_filter($output, static fn (string $p): bool => is_file(base_path($p))));
    }
}
