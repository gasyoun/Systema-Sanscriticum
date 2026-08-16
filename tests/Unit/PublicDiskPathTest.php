<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\PublicDiskPath;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * H2896 residual #9 — public-disk jail. ZIP files inside the disk stay allowed.
 */
class PublicDiskPathTest extends TestCase
{
    private string $safeRel = 'lesson-materials/notes.zip';

    private string $outside = '';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::disk('public')->put($this->safeRel, 'PK zip-body');

        $root = realpath(Storage::disk('public')->path(''));
        $this->assertNotFalse($root);
        $this->outside = dirname($root).DIRECTORY_SEPARATOR.'h2896-r9-outside.txt';
        file_put_contents($this->outside, 'secret');
    }

    protected function tearDown(): void
    {
        Storage::disk('public')->delete($this->safeRel);
        if ($this->outside !== '' && is_file($this->outside)) {
            unlink($this->outside);
        }

        parent::tearDown();
    }

    #[Test]
    public function keeps_zip_inside_the_public_disk(): void
    {
        $abs = PublicDiskPath::absoluteFile($this->safeRel);

        $this->assertNotNull($abs);
        $this->assertTrue(is_file($abs));
        $this->assertSame('PK zip-body', file_get_contents($abs));
    }

    #[Test]
    public function rejects_dotdot_even_when_it_would_land_inside(): void
    {
        $this->assertNull(PublicDiskPath::absoluteFile('lesson-materials/../lesson-materials/notes.zip'));
        $this->assertNull(PublicDiskPath::absoluteFile('../'.basename($this->outside)));
    }

    #[Test]
    public function rejects_absolute_and_empty_paths(): void
    {
        $this->assertNull(PublicDiskPath::absoluteFile(''));
        $this->assertNull(PublicDiskPath::absoluteFile(null));
        $this->assertNull(PublicDiskPath::absoluteFile('/etc/passwd'));
        $this->assertNull(PublicDiskPath::absoluteFile('C:/Windows/win.ini'));
    }
}
