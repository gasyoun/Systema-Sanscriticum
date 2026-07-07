<?php

namespace App\Observers;

use App\Models\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ScheduleObserver
{
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
     * Метод отправки данных.
     *
     * URL — из N8N_SCHEDULE_SHEET_WEBHOOK (тот же, что у кнопки выгрузки в
     * ListSchedules). Обязательно PRODUCTION-URL n8n (/webhook/...) активного
     * workflow: захардкоженный ранее /webhook-test/... живёт только пока в
     * редакторе n8n нажат «Execute workflow» → в проде каждый CRUD расписания
     * стабильно ловил 404. Пусто → интеграция выключена, не шлём ничего.
     */
    private function sendToN8n(string $action, Schedule $schedule): void
    {
        $webhookUrl = (string) config('services.n8n.schedule_sheet_webhook');
        if ($webhookUrl === '') {
            return;
        }

        try {
            // ДОБАВИЛИ timeout(2) — ждать не более 2 секунд
            // ДОБАВИЛИ retry(2, 100) — если ошибка, попробовать еще 2 раза с паузой 100мс
            Http::timeout(2)
                ->retry(2, 100)
                ->post($webhookUrl, [
                    'action' => $action,
                    'id' => $schedule->id,
                    'title' => $schedule->title,
                    'start' => $schedule->start->format('d.m.Y H:i'),
                    'group' => $schedule->group ? $schedule->group->name : 'Все',
                    'description' => $schedule->description,
                ]);
        } catch (\Exception $e) {
            // Логируем ошибку, но НЕ ЛОМАЕМ работу админки
            Log::error('n8n webhook error: '.$e->getMessage());
        }
    }
}
