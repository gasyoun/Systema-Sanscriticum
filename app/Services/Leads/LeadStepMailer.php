<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Mail\LeadWebinarInviteMail;
use App\Models\Lead;
use App\Models\LeadStepEmail;
use Illuminate\Database\QueryException;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

/**
 * Отправляет лиду письмо, когда он доходит до именованного шага бота (триггер —
 * входящий вызов из n8n). Идемпотентно: одно письмо на (lead, step).
 *
 * Новый шаг = добавить ключ в STEPS и ветку в buildMailable().
 */
class LeadStepMailer
{
    /** Известные шаги. Неизвестный шаг → 'unknown_step', письмо не шлём. */
    public const STEPS = [
        'webinar_invite',
    ];

    /**
     * @return string sent | already_sent | unknown_step | no_email | not_eligible
     */
    public function deliver(Lead $lead, string $step): string
    {
        if (! in_array($step, self::STEPS, true)) {
            return 'unknown_step';
        }

        if (empty($lead->email)) {
            return 'no_email';
        }

        $mailable = $this->buildMailable($lead, $step);
        if ($mailable === null) {
            // Шаг известен, но письмо собрать не из чего (напр. у лендинга нет webinar_url).
            return 'not_eligible';
        }

        // Идемпотентность: столбим (lead_id, step) ДО отправки. UNIQUE-индекс
        // делает повторный вызов n8n (ретрай) безопасным даже при гонке.
        try {
            LeadStepEmail::create([
                'lead_id' => $lead->id,
                'step' => $step,
                'sent_at' => now(),
            ]);
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                return 'already_sent';
            }
            throw $e;
        }

        Mail::to($lead->email)->send($mailable);

        return 'sent';
    }

    /** Сборка письма под конкретный шаг. null = шаг неприменим к этому лиду. */
    private function buildMailable(Lead $lead, string $step): ?Mailable
    {
        $landing = $lead->landingPage;

        return match ($step) {
            'webinar_invite' => ($landing && ! empty($landing->webinar_url))
                ? new LeadWebinarInviteMail($lead, $landing)
                : null,
            default => null,
        };
    }
}
