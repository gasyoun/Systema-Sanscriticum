<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $user;

    public $token;

    public $resetUrl;

    public function __construct(User $user, string $token)
    {
        $this->user = $user;
        $this->token = $token;
        $relativeResetUrl = route('password.reset', [
            'token' => $token,
            'email' => $user->email,
        ], false);

        $this->resetUrl = rtrim((string) config('app.url'), '/').$relativeResetUrl;
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Восстановление доступа в Академию 🔑',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.auth.reset',
        );
    }
}
