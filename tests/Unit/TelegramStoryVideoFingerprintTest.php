<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TelegramBusiness\TelegramStoryVideoFingerprint;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

final class TelegramStoryVideoFingerprintTest extends TestCase
{
    public function test_three_frame_fingerprint_survives_reencoding(): void
    {
        if (! Process::run(['ffmpeg', '-version'])->successful()) {
            $this->markTestSkipped('ffmpeg is required for the media fingerprint test.');
        }

        $base = tempnam(sys_get_temp_dir(), 'story-fingerprint-');
        self::assertNotFalse($base);
        @unlink($base);
        $original = $base.'.mp4';
        $reencoded = $base.'-reencoded.mp4';
        $different = $base.'-different.mp4';
        try {
            self::assertTrue(Process::timeout(30)->run([
                'ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi',
                '-i', 'testsrc2=size=180x320:rate=10', '-t', '3', '-c:v', 'libx264', $original,
            ])->successful());
            self::assertTrue(Process::timeout(30)->run([
                'ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-i', $original,
                '-c:v', 'libx264', '-crf', '32', $reencoded,
            ])->successful());
            self::assertTrue(Process::timeout(30)->run([
                'ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi',
                '-i', 'color=c=blue:size=180x320:rate=10', '-t', '3', '-c:v', 'libx264', $different,
            ])->successful());

            $service = app(TelegramStoryVideoFingerprint::class);
            $first = $service->fromFile($original, 3);
            self::assertCount(3, $first['frames']);
            self::assertTrue($service->isNear($first, $service->fromFile($reencoded, 3)));
            self::assertFalse($service->isNear($first, $service->fromFile($different, 3)));
            self::assertFalse($service->isNear($first, ['duration' => 30, 'frames' => $first['frames']]));
        } finally {
            @unlink($original);
            @unlink($reencoded);
            @unlink($different);
        }
    }
}
