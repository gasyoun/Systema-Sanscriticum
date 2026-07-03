<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;

class CertificateService
{
    public function generatePdf($certificate)
    {
        $user = $certificate->user;
        $course = $certificate->course;

        // Снимок ФИО и названия курса с фолбэком на живые значения из профиля/курса.
        // Старые сертификаты (без снимка) рендерятся как раньше.
        $studentName = $certificate->displayStudentName();
        $courseTitle = $certificate->displayCourseTitle();

        // 1. Номер и ссылка
        $certNumber = $certificate->number ?: str_pad($user->id, 8, '0', STR_PAD_LEFT);
        $verifyUrl = url('/verify/'.$certNumber);

        // 2. Подготовка QR-кода (Base64)
        $qrImage = null;
        try {
            $imgData = $this->fetchQrImage($verifyUrl);
            if ($imgData) {
                $qrImage = 'data:image/png;base64,'.base64_encode($imgData);
            }
        } catch (\Exception $e) {
        }

        // 3. Подготовка ФОНА (Base64) - чтобы картинка не пропадала
        $bgBase64 = '';
        $bgPath = $certificate->backgroundPath();
        if (file_exists($bgPath)) {
            $bgData = file_get_contents($bgPath);
            $bgBase64 = 'data:image/jpeg;base64,'.base64_encode($bgData);
        }

        $data = [
            'certificate' => $certificate,
            'user' => $user,
            'course' => $course,
            'student_name' => $studentName,
            'course_title' => $courseTitle,
            'qr_image' => $qrImage,
            'bg_base64' => $bgBase64,
            'date' => $certificate->created_at->format('d.m.Y'),
            'number' => $certNumber,
        ];

        $pdf = Pdf::loadView('certificates.default', $data);

        $pdf->setOptions([
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled' => true,
            'defaultFont' => 'DejaVu Serif',
            'dpi' => 96,
        ]);

        $pdf->setPaper('a4', 'landscape');

        return $pdf;
    }

    protected function fetchQrImage(string $verifyUrl): ?string
    {
        $apiUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data='.urlencode($verifyUrl);
        $ch = curl_init();
        curl_setopt_array($ch, $this->qrCurlOptions($apiUrl));
        $imgData = curl_exec($ch);
        curl_close($ch);

        return is_string($imgData) ? $imgData : null;
    }

    /**
     * @return array<int, mixed>
     */
    protected function qrCurlOptions(string $apiUrl): array
    {
        return [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FAILONERROR => true,
            CURLOPT_TIMEOUT => 5,
        ];
    }

    /**
     * Доступна ли генерация JPEG (нужен модуль imagick + делегат ghostscript).
     */
    public static function jpegSupported(): bool
    {
        return extension_loaded('imagick');
    }

    /**
     * Конвертирует готовый PDF (байты) в JPEG. Переиспользует выверенную
     * вёрстку PDF 1-в-1, поэтому отдельную «рисовалку» не пишем.
     */
    public function pdfToJpeg(string $pdfData, int $dpi = 200, int $quality = 90): string
    {
        if (! self::jpegSupported()) {
            throw new \RuntimeException('Для генерации JPEG нужен PHP-модуль imagick и ghostscript на сервере.');
        }

        $im = new \Imagick;
        $im->setResolution($dpi, $dpi);   // ДО readImageBlob — задаёт чёткость растра
        $im->readImageBlob($pdfData);     // PDF может быть многостраничным

        // Берём ТОЛЬКО первую страницу. Раньше тут был flattenImages(), который
        // схлопывал ВСЕ страницы поверх друг друга — и многостраничный постер
        // (карточка расписания) превращался в «кашу» из последней страницы.
        // Для сертификата (одна страница A4) поведение не меняется.
        $im->setIteratorIndex(0);
        $page = $im->getImage();
        $page->setImageBackgroundColor('white');
        $page = $page->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN); // прозрачность → белый
        $page->setImageFormat('jpeg');
        $page->setImageCompressionQuality($quality);

        $blob = $page->getImageBlob();
        $page->clear();
        $im->clear();

        return $blob;
    }

    /**
     * Полный цикл: сертификат → PDF → JPEG (байты).
     */
    public function generateJpegBytes($certificate): string
    {
        return $this->pdfToJpeg($this->generatePdf($certificate)->output());
    }
}
