<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendTelegramChatMessageJob;
use App\Models\Lead;

/**
 * Уведомления маркетологам в общий Telegram-чат о новых лидах с лендингов/статей.
 *
 * Получатель — config('services.telegram.marketers_chat_id'). Если он не задан,
 * метод — no-op (фича выключена). Отправляет основной бот
 * (config('services.telegram.bot_token')) через SendTelegramChatMessageJob.
 *
 * Дубликаты заявок сюда не доходят: LeadController отсекает их до Lead::create().
 */
class LeadNotifier
{
    public function newLead(Lead $lead): void
    {
        $chatId = (string) (config('services.telegram.marketers_chat_id') ?? '');
        if ($chatId === '') {
            return; // чат не настроен — фича выключена
        }

        SendTelegramChatMessageJob::dispatch($chatId, $this->buildMessage($lead));
    }

    private function buildMessage(Lead $lead): string
    {
        $lines = [
            '🎯 <b>Новый лид</b>',
            '',
            'Имя: <b>'.e((string) $lead->name).'</b>',
            'Телефон: <b>'.e((string) $lead->contact).'</b>',
        ];

        if (! empty($lead->email)) {
            $lines[] = 'Email: '.e((string) $lead->email);
        }
        if (! empty($lead->social)) {
            $lines[] = 'Соцсеть: '.e((string) $lead->social);
        }

        $source = $this->sourceLine($lead);
        if ($source !== null) {
            $lines[] = $source;
        }

        $lines[] = $this->utmLine($lead);
        $lines[] = 'Рассылка: '.($lead->is_promo_agreed ? 'да' : 'нет');
        $lines[] = 'Дата: '.($lead->created_at?->format('d.m.Y H:i') ?? '—');
        $lines[] = $this->adminLink();

        return implode("\n", array_filter($lines, fn ($l) => $l !== null && $l !== ''));
    }

    /** Источник: лендинг или статья. */
    private function sourceLine(Lead $lead): ?string
    {
        if ($lead->landing_page_id && $lead->landingPage) {
            return 'Лендинг: <b>'.e((string) $lead->landingPage->title).'</b>';
        }
        if (! empty($lead->source_article_slug)) {
            return 'Статья: <b>'.e((string) $lead->source_article_slug).'</b>';
        }

        return null;
    }

    /** UTM-метки + распознанная форма (utm_content уже содержит [form_name]). */
    private function utmLine(Lead $lead): ?string
    {
        $parts = array_filter([
            $lead->utm_source ? 'source: '.e((string) $lead->utm_source) : null,
            $lead->utm_medium ? 'medium: '.e((string) $lead->utm_medium) : null,
            $lead->utm_campaign ? 'campaign: '.e((string) $lead->utm_campaign) : null,
            $lead->utm_content ? 'content: '.e((string) $lead->utm_content) : null,
        ]);

        return $parts ? 'UTM: '.implode(' · ', $parts) : null;
    }

    private function adminLink(): string
    {
        try {
            $url = \App\Filament\Resources\LeadResource::getUrl('index');
        } catch (\Throwable) {
            $url = url('/admin');
        }

        return "👉 <a href=\"{$url}\">Список лидов</a>";
    }
}
