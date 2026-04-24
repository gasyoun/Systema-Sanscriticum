<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;

class CertificateService
{
    public function generatePdf($certificate)
    {
        $user = $certificate->user;
        $course = $certificate->course;

        // 1. Номер и ссылка
        $certNumber = $certificate->number ?: str_pad($user->id, 8, '0', STR_PAD_LEFT);
        $verifyUrl = url('/verify/' . $certNumber);

        // 2. Подготовка QR-кода (Base64)
        $qrImage = null;
        try {
            $apiUrl = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($verifyUrl);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            $imgData = curl_exec($ch);
            curl_close($ch);
            if ($imgData) {
                $qrImage = 'data:image/png;base64,' . base64_encode($imgData);
            }
        } catch (\Exception $e) { }

        // 3. Подготовка ФОНА (Base64) - чтобы картинка не пропадала
        $bgBase64 = '';
        $bgPath = public_path('images/ganesha_clean.jpg');
        if (file_exists($bgPath)) {
            $bgData = file_get_contents($bgPath);
            $bgBase64 = 'data:image/jpeg;base64,' . base64_encode($bgData);
        }

        $data = [
            'user' => $user,
            'course' => $course,
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
            'dpi' => 96
        ]);

        $pdf->setPaper('a4', 'landscape');

        return $pdf;
    }
}
