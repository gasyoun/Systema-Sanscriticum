<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Certificate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Письмо студенту о выданном сертификате (автовыдача по вехе). Сам PDF не
 * прикладываем — он генерируется на лету при скачивании из кабинета; письмо
 * ведёт на student.dashboard, где уже есть кнопки PDF/JPG.
 *
 * Транзакционное: НЕ зависит от wants_email_announcements.
 */
class CertificateIssuedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Certificate $certificate)
    {
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Вам выдан сертификат — '.$this->certificate->displayCourseTitle(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.certificate-issued',
            with: [
                'certificate' => $this->certificate,
                'dashboardUrl' => route('student.dashboard'),
            ],
        );
    }
}
