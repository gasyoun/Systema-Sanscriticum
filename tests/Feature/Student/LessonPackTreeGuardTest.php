<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Support\Lyw\LessonPackTreeGuard;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * H7069 — tracked LYW pack tree + fail-loud guard.
 *
 * Проверки идут по НАСТОЯЩЕМУ дереву (resources/data/lesson_packs, вложенный
 * zan1/<level>/<interest>), а не по плоской фикстуре: именно такая форма лежит
 * на .92 и именно её должен ловить guard.
 */
class LessonPackTreeGuardTest extends TestCase
{
    private const APPROVED = [
        'base' => true,
        'nol-ayurveda' => true,
        'nol-kino' => true,
        'nol-palomnichestvo' => true,
        'nol-yoga' => true,
    ];

    public function test_committed_tree_satisfies_every_enabled_group(): void
    {
        config(['lyw.enabled' => true, 'lyw.enabled_groups' => self::APPROVED]);

        $this->assertSame(
            base_path('resources/data/lesson_packs'),
            config('lyw.packs_path'),
            'packs_path должен по умолчанию указывать на tracked-дерево',
        );
        $this->assertSame([], LessonPackTreeGuard::problemsFromConfig());
        $this->assertSame(9, $this->manifestCount(), 'в tracked-дереве должны лежать все 9 паков');
    }

    public function test_nested_enabled_manifest_removal_fails_and_restore_clears(): void
    {
        $root = $this->copyCommittedTree();
        config([
            'lyw.enabled' => true,
            'lyw.enabled_groups' => self::APPROVED,
            'lyw.packs_path' => $root,
        ]);

        $this->assertSame([], LessonPackTreeGuard::problemsFromConfig());

        $manifest = $root.'/zan1/nol/yoga/manifest.json';
        unlink($manifest);

        $problems = LessonPackTreeGuard::problemsFromConfig();
        $this->assertNotSame([], $problems);
        $this->assertStringContainsString('nol-yoga', implode("\n", $problems));

        copy(base_path('resources/data/lesson_packs/zan1/nol/yoga/manifest.json'), $manifest);
        $this->assertSame([], LessonPackTreeGuard::problemsFromConfig());
    }

    public function test_empty_tree_is_allowed_while_master_switch_is_off(): void
    {
        $empty = sys_get_temp_dir().'/lyw-empty-'.uniqid('', true);
        mkdir($empty, 0777, true);

        config([
            'lyw.enabled' => false,
            'lyw.enabled_groups' => self::APPROVED,
            'lyw.packs_path' => $empty,
        ]);

        $this->assertSame([], LessonPackTreeGuard::problemsFromConfig());
    }

    private function manifestCount(): int
    {
        $root = base_path('resources/data/lesson_packs/zan1');
        $count = 0;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if ($file->isFile() && $file->getFilename() === 'manifest.json') {
                $count++;
            }
        }

        return $count;
    }

    private function copyCommittedTree(): string
    {
        $src = base_path('resources/data/lesson_packs');
        $dst = sys_get_temp_dir().'/lyw-guard-'.uniqid('', true);
        $this->copyDirectory($src, $dst);

        return $dst;
    }

    private function copyDirectory(string $src, string $dst): void
    {
        if (! is_dir($dst)) {
            mkdir($dst, 0777, true);
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($items as $item) {
            $target = $dst.'/'.$items->getSubPathName();
            if ($item->isDir()) {
                if (! is_dir($target)) {
                    mkdir($target, 0777, true);
                }
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }
}
