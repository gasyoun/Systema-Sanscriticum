<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Http\Middleware\CaptureAttribution;
use App\Models\Lead;
use App\Models\SupportConversation;

/**
 * H1199 — Jivo-паритет S4: захват необязательного телефона/почты из веб-виджета
 * в Lead-строку. Тот же паттерн, что NewsletterSubscriptionService::recordLead
 * (H324) — UTM из сессии CaptureAttribution, dedup по email, ортогонально
 * платному ядру (никаких групп/оплат/тарифов не трогает).
 *
 * Идемпотентно НА ТРЕД: захват происходит один раз (обычно первое сообщение с
 * заполненным контактом) — thread.lead_captured_at маркирует «попытка была»,
 * как visitor_geo_resolved_at (H1196). Повторные сообщения того же треда не
 * переспрашивают и не дублируют Lead.
 */
class SupportLeadCaptureService
{
    public function capture(SupportConversation $thread, ?string $name, ?string $email, ?string $phone): void
    {
        if ($thread->lead_captured_at !== null) {
            return;
        }

        $email = $this->normalize($email);
        $phone = $this->normalize($phone);

        if ($email === null && $phone === null) {
            return;
        }

        $lead = $this->recordLead($thread, $name, $email, $phone);

        $thread->forceFill([
            'lead_id' => $lead->id,
            'contact_email' => $email,
            'contact_phone' => $phone,
            'lead_captured_at' => now(),
        ])->save();
    }

    private function recordLead(SupportConversation $thread, ?string $name, ?string $email, ?string $phone): Lead
    {
        $session = session(CaptureAttribution::SESSION_KEY, []);
        $utm = array_intersect_key($session, array_flip(CaptureAttribution::UTM_FIELDS));

        $attributes = array_merge($utm, [
            'name' => $name ?: $thread->displayName(),
            // leads.contact — обязательное поле «основной контакт» (соглашение
            // всех писателей Lead: LeadController/MarathonController/
            // NewsletterSubscriptionService); телефон приоритетнее, иначе email.
            'contact' => $phone ?? $email,
            'email' => $email,
            'utm_content' => trim('[webchat] '.($utm['utm_content'] ?? '')),
            'referrer' => $thread->referrer,
            'ip_address' => $thread->visitor_ip,
            'landing_page_id' => null,
        ]);

        // Email — надёжный ключ дедупа (как newsletter_subscribe); телефон-only
        // лиды дедупить не пытаемся здесь — контакт руками сверяет менеджер,
        // как для любой другой заявки без email.
        if ($email !== null) {
            return Lead::updateOrCreate(
                ['email' => $email, 'utm_content' => $attributes['utm_content']],
                $attributes,
            );
        }

        return Lead::create($attributes);
    }

    private function normalize(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
