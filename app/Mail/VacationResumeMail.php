<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Group;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * H6343: email-канал анонса возобновления занятий после каникул (текст тот же,
 * что в ЛС/чате: диапазон дат + число занятий + календарь + ICS-подписка).
 */
class VacationResumeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Group $group,
        public readonly string $resumeText,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Занятия возобновляются — '.$this->group->name,
        );
    }

    /**
     * Текст — уже HTML (тот же, что уходит в Telegram с parse_mode=HTML:
     * <b>, <a href>, название группы экранировано в resumeText()). Повторное
     * e() показывало теги буквами, а почтовик автоссылкой захватывал
     * закрывающую кавычку href — ссылки календаря и ICS отдавали 404.
     */
    public function content(): Content
    {
        return new Content(htmlString: nl2br($this->resumeText));
    }
}
