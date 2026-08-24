<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\WorkQueueReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * NOBORING дожим, операторская сводка (решение MG 24-08-2026, гибрид):
 * будни 10:00 Europe/Moscow — Telegram-сообщение владельцу очереди со
 * списком недожатых open Deal ({@see WorkQueueReport::agedOpenDeals()},
 * без гейта dozhim_queue — видимость и доставка независимы).
 *
 * Получатель: config('services.dozhim_operator_tg_chat_id') (числовой
 * chat_id, сырой sendMessage через основной бот) либо первый User роли
 * manager с привязанным telegram_id (личные уведомления — тот же путь,
 * что у студентов: User::sendTelegramMessage()).
 *
 * Пустая очередь НЕ отправляет ничего — тишина значит «всё дожато».
 * Флаг — dozhim_operator_notify; --force отправляет и при OFF (для ручной
 * проверки доставки), но не при пустом списке без --force.
 */
class DozhimNotifyOperatorCommand extends Command
{
    protected $signature = 'dozhim:notify-operator {--force : Отправить даже при выключенном флаге или пустой очереди (проверка доставки)}';

    protected $description = 'Telegram-сводка недожатых open Deal владельцу очереди (будни 10:00)';

    public function handle(WorkQueueReport $report): int
    {
        if (! config('features.dozhim_operator_notify') && ! $this->option('force')) {
            $this->info('Операторская сводка выключена (DOZHIM_OPERATOR_NOTIFY) — пропуск.');

            return self::SUCCESS;
        }

        $deals = $report->agedOpenDeals();

        if ($deals->isEmpty() && ! $this->option('force')) {
            return self::SUCCESS;
        }

        [$recipient, $via] = $this->recipient();

        if ($recipient === null || $recipient === '') {
            Log::warning('dozhim:notify-operator — получатель не найден', [
                'hint' => 'задайте DOZHIM_OPERATOR_TG_CHAT_ID или привяжите telegram_id пользователю роли manager',
            ]);
            $this->error('Получатель не найден: DOZHIM_OPERATOR_TG_CHAT_ID / manager с telegram_id.');

            return self::FAILURE;
        }

        $text = $this->renderDigest($deals, $report);

        $sent = is_numeric($recipient)
            ? $this->sendRaw((string) $recipient, $text)
            : $recipient->sendTelegramMessage($text) !== false;

        if (! $sent) {
            Log::error('dozhim:notify-operator — Telegram sendMessage не удался', ['via' => $via]);
            $this->error('Отправка в Telegram не удалась — см. лог.');

            return self::FAILURE;
        }

        $this->info("Сводка отправлена ({$via}): сделок в списке {$deals->count()}.");

        return self::SUCCESS;
    }

    /**
     * @return array{0: object|string|null, 1: string} получатель + канал («chat_id» / «user»)
     */
    private function recipient(): array
    {
        $chatId = trim((string) config('services.dozhim_operator_tg_chat_id'));

        if ($chatId !== '') {
            return [$chatId, 'chat_id'];
        }

        $manager = User::query()
            ->where('role', 'manager')
            ->whereNotNull('telegram_id')
            ->where('telegram_id', '!=', '')
            ->orderBy('id')
            ->first();

        return $manager !== null ? [$manager, 'user'] : [null, 'none'];
    }

    private function renderDigest($deals, WorkQueueReport $report): string
    {
        $url = rtrim(url('/'), '/').'/admin/work-queue';
        $lines = [];

        foreach ($deals as $deal) {
            $name = optional($deal->user)->name ?? '—';
            $course = optional($deal->course)->title ?? '—';
            $amount = number_format((float) $deal->amount, 0, ',', ' ').' '.($deal->currency ?? '');
            $ageDays = max(1, (int) $deal->created_at->startOfDay()->diffInDays(now()->startOfDay()));

            $lines[] = sprintf(
                '• <b>%s</b> — %s — %s — ждёт %d дн.',
                e($name),
                e(mb_substr($course, 0, 60)),
                e($amount),
                $ageDays
            );
        }

        $header = $deals->isEmpty()
            ? '<b>Дожим 10:00:</b> недожатых нет — всё дожато ✅'
            : sprintf('<b>Дожим 10:00:</b> недожатых сделок — %d', $deals->count());

        return implode("\n", [
            $header,
            '',
            ...$lines,
            '',
            'Разобрать: '.$url,
        ]);
    }

    private function sendRaw(string $chatId, string $text): bool
    {
        $token = (string) (config('services.telegram.bot_token') ?: config('services.telegram.student_bot_token'));

        if ($token === '') {
            Log::error('dozhim:notify-operator — не задан токен бота');

            return false;
        }

        try {
            $response = Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);
        } catch (\Throwable $e) {
            Log::error('dozhim:notify-operator — исключение при отправке', ['error' => $e->getMessage()]);

            return false;
        }

        if (! $response->successful() || $response->json('ok') !== true) {
            Log::error('dozhim:notify-operator — Telegram отклонил sendMessage', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 300),
            ]);

            return false;
        }

        return true;
    }
}
