<?php

declare(strict_types=1);

namespace App\Services\TelegramBusiness;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/** Three-frame dHash: a cheap review signal, never an automatic duplicate verdict. */
final class TelegramStoryVideoFingerprint
{
    /** @return array{duration: float, frames: list<string>} */
    public function fromFile(string $path, float $duration): array
    {
        $frames = [];
        foreach ([0.2, 0.5, 0.8] as $fraction) {
            $seek = max(0, min($duration - 0.1, $duration * $fraction));
            $result = Process::timeout(30)->run([
                (string) config('services.telegram_business.story_ffmpeg_binary', 'ffmpeg'),
                '-hide_banner', '-loglevel', 'error', '-ss', (string) $seek,
                '-i', $path, '-frames:v', '1', '-vf', 'scale=9:8,format=gray',
                '-f', 'rawvideo', '-',
            ]);
            $pixels = $result->output();
            if (! $result->successful() || strlen($pixels) !== 72) {
                throw new RuntimeException('Unable to fingerprint Story video frame.');
            }
            $bits = '';
            for ($y = 0; $y < 8; $y++) {
                for ($x = 0; $x < 8; $x++) {
                    $bits .= ord($pixels[$y * 9 + $x]) > ord($pixels[$y * 9 + $x + 1]) ? '1' : '0';
                }
            }
            $frames[] = implode('', array_map(
                fn (string $nibble): string => dechex(bindec($nibble)),
                str_split($bits, 4),
            ));
        }

        return ['duration' => $duration, 'frames' => $frames];
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    public function isNear(array $left, array $right): bool
    {
        $a = $left['frames'] ?? null;
        $b = $right['frames'] ?? null;
        $durationA = (float) ($left['duration'] ?? 0);
        $durationB = (float) ($right['duration'] ?? 0);
        if (! is_array($a) || ! is_array($b) || count($a) !== 3 || count($b) !== 3
            || $durationA <= 0 || $durationB <= 0
            || abs($durationA - $durationB) > max(2, min($durationA, $durationB) * 0.1)) {
            return false;
        }

        $total = 0;
        $information = 0;
        foreach ($a as $index => $frame) {
            if (! is_string($frame) || ! is_string($b[$index])
                || ! preg_match('/\A[0-9a-f]{16}\z/', $frame)
                || ! preg_match('/\A[0-9a-f]{16}\z/', $b[$index])) {
                return false;
            }
            $distance = 0;
            for ($i = 0; $i < 16; $i++) {
                $distance += substr_count(decbin(hexdec($frame[$i]) ^ hexdec($b[$index][$i])), '1');
            }
            if ($distance > 8) {
                return false;
            }
            $total += $distance;
            $information += substr_count(implode('', array_map(
                fn (string $hex): string => str_pad(decbin(hexdec($hex)), 4, '0', STR_PAD_LEFT),
                str_split($frame),
            )), '1');
        }

        return $information >= 12 && $total <= 18;
    }
}
