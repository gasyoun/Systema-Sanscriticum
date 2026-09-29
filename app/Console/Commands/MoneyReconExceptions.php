<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MoneyReconException;
use App\Models\User;
use App\Services\Reconciliation\ExceptionQueue;
use App\Services\Reconciliation\ReconInvariantViolation;
use Illuminate\Console\Command;

/**
 * H5445 (P3): очередь исключений сверки.
 *
 *   php artisan money:recon-exceptions                      # открытые, сводка по типам + последние 50
 *   php artisan money:recon-exceptions --type=refund_without_blocks --limit=200
 *   php artisan money:recon-exceptions --show=17            # доказательство и след
 *   php artisan money:recon-exceptions --resolve=17 --by=1 --reason="доступ снят вручную, тикет #…"
 *   php artisan money:recon-exceptions --dismiss=17 --by=1 --reason="ложное: …"
 *   php artisan money:recon-exceptions --note=17 --by=1 --reason="ждём выписку"
 *
 * Разрешение ничего не делает с деньгами и доступом — оно фиксирует, кто и
 * почему закрыл исключение (D9/D17/D21). Само исправление — штатными
 * инструментами (админка, сторно/корректировка ядра).
 */
class MoneyReconExceptions extends Command
{
    protected $signature = 'money:recon-exceptions
        {--state=open : open|resolved|dismissed|all}
        {--type= : тип исключения}
        {--limit=50}
        {--show= : id исключения — показать доказательство и след}
        {--resolve= : id исключения — разрешить}
        {--dismiss= : id исключения — отклонить как ложное}
        {--note= : id исключения — добавить заметку}
        {--by= : id пользователя-автора действия (обязателен для действий)}
        {--reason= : основание (обязательно для действий)}';

    protected $description = 'H5445 (P3): очередь исключений денежной сверки — просмотр и разрешение с основанием';

    public function handle(ExceptionQueue $queue): int
    {
        foreach (['resolve', 'dismiss', 'note'] as $action) {
            if ($this->option($action) !== null) {
                return $this->act($queue, $action, (int) $this->option($action));
            }
        }
        if ($this->option('show') !== null) {
            return $this->show((int) $this->option('show'));
        }

        $state = (string) $this->option('state');
        $q = MoneyReconException::query()
            ->when($state !== 'all', fn ($q) => $q->where('state', $state))
            ->when($this->option('type'), fn ($q, $t) => $q->where('type', $t));

        $byType = (clone $q)->selectRaw('type, COUNT(*) AS n, COALESCE(SUM(amount_kopecks), 0) AS kop')->groupBy('type')->orderBy('type')->get();
        $this->table(['тип', 'шт', '₽'], $byType->map(fn ($r) => [$r->type, $r->n, number_format($r->kop / 100, 2, '.', ' ')])->all());

        $rows = $q->orderByDesc('id')->limit(max(1, (int) $this->option('limit')))->get();
        $this->table(['id', 'тип', 'деталь', 'источник', 'строка', '₽', 'состояние', 'прогон'], $rows->map(fn (MoneyReconException $e) => [
            $e->id, $e->type, $e->evidence['detail'] ?? '', $e->source, $e->source_ref,
            $e->amount_kopecks !== null ? number_format($e->amount_kopecks / 100, 2, '.', ' ') : '',
            $e->state, $e->first_run_id.'→'.$e->last_seen_run_id,
        ])->all());

        return self::SUCCESS;
    }

    private function show(int $id): int
    {
        $e = MoneyReconException::query()->with('events')->find($id);
        if ($e === null) {
            $this->error("исключение #{$id} не найдено");

            return self::FAILURE;
        }
        $this->line(json_encode($e->only(['id', 'type', 'source', 'source_ref', 'state', 'amount_kopecks', 'currency', 'user_id', 'first_run_id', 'last_seen_run_id']) + ['evidence' => $e->evidence], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        foreach ($e->events as $ev) {
            $this->line(sprintf('  %s %-9s run=%s actor=%s %s', $ev->created_at, $ev->event, $ev->run_id ?? '—', $ev->actor_id ?? '—', $ev->reason ?? ''));
        }

        return self::SUCCESS;
    }

    private function act(ExceptionQueue $queue, string $action, int $id): int
    {
        $by = (int) $this->option('by');
        $reason = trim((string) $this->option('reason'));
        if ($by <= 0 || ! User::query()->whereKey($by)->exists()) {
            $this->error('--by=<id пользователя> обязателен: у ручного действия есть автор (D17).');

            return self::INVALID;
        }
        $e = MoneyReconException::query()->find($id);
        if ($e === null) {
            $this->error("исключение #{$id} не найдено");

            return self::FAILURE;
        }

        try {
            match ($action) {
                'resolve' => $queue->resolve($e, $by, $reason),
                'dismiss' => $queue->dismiss($e, $by, $reason),
                'note' => $queue->note($e, $by, $reason),
            };
        } catch (ReconInvariantViolation $ex) {
            $this->error($ex->getMessage());

            return self::FAILURE;
        }
        $this->info("#{$id}: {$action} записано.");

        return self::SUCCESS;
    }
}
