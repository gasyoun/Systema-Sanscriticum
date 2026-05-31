<?php

namespace App\Http\Controllers;

use App\Models\Certificate;

class CertificateVerificationController extends Controller
{
    /**
     * Публичная страница верификации сертификата по номеру (ссылка из QR-кода).
     */
    public function show(string $number)
    {
        $certificate = Certificate::with(['user', 'course'])
            ->where('number', $number)
            ->first();

        return view('certificate.verify', compact('certificate', 'number'));
    }
}
