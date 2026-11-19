<?php

namespace App\Http\Controllers\Concerns;

use App\Services\CertificateService;

/**
 * Domain-scoped concern for StudentController — extracted by H4978 split
 * (recovered under H5245 PR-sweep). Implementations are main's current bodies.
 */
trait StudentCertificateConcerns
{
    /**
     * Скачивание сертификата
     */
    public function downloadCertificate($id, CertificateService $service)
    {
        $certificate = auth()->user()->certificates()->with('course')->findOrFail($id);
        $pdf = $service->generatePdf($certificate);

        return $pdf->download('Certificate_'.$certificate->course->id.'.pdf');
    }

    /**
     * Скачивание сертификата картинкой (JPEG).
     */
    public function downloadCertificateImage($id, CertificateService $service)
    {
        $certificate = auth()->user()->certificates()->with('course')->findOrFail($id);

        try {
            $jpeg = $service->generateJpegBytes($certificate);
        } catch (\RuntimeException|\ImagickException $e) {
            return back()->with('error', $e->getMessage());
        }

        return response()->streamDownload(
            fn () => print $jpeg,
            'Certificate_'.$certificate->course->id.'.jpg',
            ['Content-Type' => 'image/jpeg'],
        );
    }

    /**
     * === ВСПОМОГАТЕЛЬНЫЙ МЕТОД: Парсер ссылок видео ===
     * H4396: public static — RecordingGateController (серверные ворота
     * записи) резолвит тот же ID, не плодя вторую реализацию правила
     * «какая ссылка чем открывается» (прецедент H3308).
     */
    public static function parseVideoId(?string $url, string $platform): ?string
    {
        if (! $url) {
            return null;
        }

        if ($platform === 'youtube') {
            preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/', $url, $matches);

            return $matches[1] ?? null;
        }

        if ($platform === 'rutube') {
            $parsed = parse_url($url);
            $path = $parsed['path'] ?? '';
            $query = $parsed['query'] ?? '';

            if (preg_match('/([a-zA-Z0-9]{32})/', $path, $matches)) {
                $cleanId = $matches[1];
                if (! empty($query)) {
                    return $cleanId.'?'.$query;
                }

                return $cleanId;
            }
        }

        // VK: страница просмотра (vk.com/video-88831040_456239808, vkvideo.ru/video…)
        // или готовый embed (video_ext.php?oid=-88831040&id=…). Возвращаем «oid_id».
        if ($platform === 'vk') {
            if (preg_match('/video(-?\d+)_(\d+)/', $url, $matches)) {
                return $matches[1].'_'.$matches[2];
            }
            if (preg_match('/video_ext\.php\?[^#]*oid=(-?\d+)[&;]+id=(\d+)/', $url, $matches)) {
                return $matches[1].'_'.$matches[2];
            }
        }

        return null;
    }
}
