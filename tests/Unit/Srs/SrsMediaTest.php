<?php

declare(strict_types=1);

namespace Tests\Unit\Srs;

use App\Models\SrsDeck;
use App\Services\Srs\SrsMedia;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SrsMediaTest extends TestCase
{
    public function test_field_path_for_storage_rewrites_media_prefix(): void
    {
        $this->assertSame(
            'srs/anki_454628379/284860.mp3',
            SrsMedia::fieldPathForStorage('media/284860.mp3', '454628379'),
        );
    }

    public function test_url_resolves_legacy_media_path_via_deck_slug(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('srs/anki_454628379/clip.mp3', 'x');

        $deck = new SrsDeck(['slug' => 'anki-454628379-level-1']);

        $url = SrsMedia::url('media/clip.mp3', $deck);
        $this->assertNotNull($url);
        $this->assertStringContainsString('storage/srs/anki_454628379/clip.mp3', $url);
    }

    public function test_url_returns_null_when_file_missing(): void
    {
        Storage::fake('public');
        $deck = new SrsDeck(['slug' => 'anki-454628379-level-1']);

        $this->assertNull(SrsMedia::url('media/missing.mp3', $deck));
    }

    public function test_publish_directory_writes_basenames_only(): void
    {
        Storage::fake('public');
        $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'anki_media_'.uniqid();
        mkdir($tmp);
        file_put_contents($tmp.DIRECTORY_SEPARATOR.'a.mp3', 'a');
        file_put_contents($tmp.DIRECTORY_SEPARATOR.'b.jpg', 'b');

        try {
            $n = SrsMedia::publishDirectory($tmp, 'X1');
            $this->assertSame(2, $n);
            Storage::disk('public')->assertExists('srs/anki_X1/a.mp3');
            Storage::disk('public')->assertExists('srs/anki_X1/b.jpg');
        } finally {
            @unlink($tmp.DIRECTORY_SEPARATOR.'a.mp3');
            @unlink($tmp.DIRECTORY_SEPARATOR.'b.jpg');
            @rmdir($tmp);
        }
    }
}
