<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ServerGuards\CompiledViewsOwnershipInspector;
use PHPUnit\Framework\TestCase;

/**
 * H4848 — детектор root-овых скомпилированных Blade проверяется на фикстуре.
 *
 * На реальном `storage/framework/views` тест проверял бы только «зелёную»
 * ветку: каталог локальной машины всегда writable, а дефект — это файл,
 * который НЕ writable. Поэтому фикстура, где один файл переведён в read-only
 * (на POSIX — снятое право записи, на Windows — read-only атрибут), и есть
 * настоящий тест инварианта.
 */
final class CompiledViewsOwnershipInspectorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'h4848-views-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @chmod($file, 0666);
            @unlink($file);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function test_reports_only_files_this_process_cannot_write(): void
    {
        $writable = $this->dir.DIRECTORY_SEPARATOR.'healthy.php';
        $blocked = $this->dir.DIRECTORY_SEPARATOR.'root-owned.php';

        file_put_contents($writable, '<?php // compiled view');
        file_put_contents($blocked, '<?php // compiled view');
        chmod($blocked, 0444);

        if (is_writable($blocked)) {
            // Running as root (or a filesystem that ignores read-only): the
            // fixture cannot express the defect, so the assertion would be a lie.
            $this->markTestSkipped('filesystem does not honour read-only mode for this user');
        }

        $found = array_map(
            static fn (string $p): string => str_replace('\\', '/', $p),
            (new CompiledViewsOwnershipInspector($this->dir))->unwritableFiles(),
        );

        $this->assertSame(
            [str_replace('\\', '/', $blocked)],
            $found,
            'only the non-writable compiled view is a defect — a writable one must not be reported',
        );
    }

    public function test_missing_directory_is_not_a_violation(): void
    {
        $inspector = new CompiledViewsOwnershipInspector($this->dir.DIRECTORY_SEPARATOR.'not-there');

        $this->assertSame(
            [],
            $inspector->unwritableFiles(),
            'optimize:clear removes the directory between deploys — absence is not root-ownership',
        );
    }

    public function test_owner_and_current_user_are_named_for_the_alert(): void
    {
        $file = $this->dir.DIRECTORY_SEPARATOR.'named.php';
        file_put_contents($file, '<?php // compiled view');

        $inspector = new CompiledViewsOwnershipInspector($this->dir);

        $this->assertNotSame('', $inspector->ownerOf($file));
        $this->assertNotSame('', $inspector->currentUser());
    }
}
