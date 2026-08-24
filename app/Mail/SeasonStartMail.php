<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Season;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * H3297 — письмо студенту о старте игрового сезона (/lila). Ставится в
 * очередь «mailing». Контент: даты сезона, что такое прана/ранги, семантика
 * лидерборда (базовый снапшот, P2P-immune), куда идти играть.
 *
 * Строки сезона на момент рассылки (T-24h) может ещё не быть — тогда берём
 * дефолты из config('season.defaults').
 */
class SeasonStartMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public ?Season $season = null)
    {
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Стартует '.$this->seasonTitle().' — зарабатывайте прану в /lila!',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.season-start',
            with: [
                'firstName' => 'друг',
                'title' => $this->seasonTitle(),
                'startDate' => $this->startDate()->locale('ru')->translatedFormat('j F Y'),
                'endDate' => $this->endDate()->locale('ru')->translatedFormat('j F Y'),
                'rewards' => $this->rewards(),
                'playUrl' => url('/lila/'),
            ],
        );
    }

    public function seasonTitle(): string
    {
        return $this->season?->title ?? (string) config('season.defaults.title');
    }

    public function startDate(): Carbon
    {
        return Carbon::parse($this->season?->started_at ?? config('season.defaults.starts_at'));
    }

    public function endDate(): Carbon
    {
        return Carbon::parse($this->season?->ended_at ?? config('season.defaults.ends_at'));
    }

    /**
     * Призы сезона: из строки сезона, иначе дефолт Сезона 1.
     *
     * @return array<int, array{position: int, amount: int}>
     */
    public function rewards(): array
    {
        $fromRow = collect($this->season?->rewards_config ?? [])
            ->filter(fn ($r) => ($r['type'] ?? null) === 'prana')
            ->map(fn ($r) => ['position' => (int) $r['position'], 'amount' => (int) $r['amount']])
            ->values()
            ->all();

        if ($fromRow !== []) {
            return $fromRow;
        }

        return array_map(
            fn (array $r): array => ['position' => (int) $r['position'], 'amount' => (int) $r['amount']],
            config('season.defaults.rewards', []),
        );
    }
}
