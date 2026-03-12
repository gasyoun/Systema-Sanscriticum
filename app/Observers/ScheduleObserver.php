<?php

namespace App\Observers;

use App\Models\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ScheduleObserver
{
    // Сюда вставь свой Webhook URL из n8n (Webhook Node -> Production URL)
    private string $webhookUrl = 'https://context-ai.ru/webhook-test/6a4e0703-4059-47ba-8bad-c3c3d51447ff';

    /**
     * Срабатывает, когда создано новое событие
     */
    public function created(Schedule $schedule): void
    {
        $this->sendToN8n('create', $schedule);
    }

    /**
     * Срабатывает, когда событие обновлено
     */
    public function updated(Schedule $schedule): void
    {
        $this->sendToN8n('update', $schedule);
    }

    /**
     * Срабатывает, когда событие удалено
     */
    public function deleted(Schedule $schedule): void
    {
        $this->sendToN8n('delete', $schedule);
    }

    /**
     * Метод отправки данных
     */
    private function sendToN8n(string $action, Schedule $schedule): void
    {
        try {
            // ДОБАВИЛИ timeout(2) — ждать не более 2 секунд
            // ДОБАВИЛИ retry(2, 100) — если ошибка, попробовать еще 2 раза с паузой 100мс
            Http::timeout(2)
                ->retry(2, 100)
                ->post($this->webhookUrl, [
                    'action' => $action,
                    'id' => $schedule->id,
                    'title' => $schedule->title,
                    'start' => $schedule->start->format('d.m.Y H:i'),
                    'group' => $schedule->group ? $schedule->group->name : 'Все',
                    'description' => $schedule->description,
                ]);
        } catch (\Exception $e) {
            // Логируем ошибку, но НЕ ЛОМАЕМ работу админки
            Log::error('n8n webhook error: ' . $e->getMessage());
        }
    }
}