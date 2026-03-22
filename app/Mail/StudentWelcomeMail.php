<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StudentWelcomeMail extends Mailable implements ShouldQueue // ShouldQueue отправит письмо в фоне, чтобы админ не ждал
{
    use Queueable, SerializesModels;

    public $user;
    public $password;

    public function __construct(User $user, $password)
    {
        $this->user = $user;
        $this->password = $password;
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Добро пожаловать в Академию! Ваши данные для входа 🎉',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.student.welcome', // Путь к шаблону письма
        );
    }
}