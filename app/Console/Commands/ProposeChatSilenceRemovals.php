<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Discipline\ChatSilenceCandidate;
use App\Services\Discipline\ChatSilenceRemovalEligibility;
use App\Services\Discipline\ChatSilenceRemovalService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Раз в день: кто молчит в учебном чате и кому кабинет закрыл последний
 * урок — админам приходит предложение с кнопкой «Исключить» (банит Почтальон
 * только по нажатию). Без CHAT_SILENCE_REMOVAL_ENABLED работает лишь --dry-run.
 *
 * Сухой прогон по умолчанию прячет имена (user#123 / tg#123): вывод уезжает в
 * лог и в чат. --reveal включает их осознанно.
 */
class ProposeChatSilenceRemovals extends Command
{
    protected $signature = 'chats:silence-removal-propose
        {--dry-run : ничего не писать и не отправлять — только показать}
        {--all : в сухом прогоне показать и тех, кто правило не проходит}
        {--reveal : печатать имена вместо user#id}';

    protected $description = 'Предложить админам исключить из учебных TG-чатов тех, кто молчит N дней и кому закрыт урок (кнопка → бан Почтальоном).';

    public function handle(ChatSilenceRemovalService $service, ChatSilenceRemovalEligibility $eligibility): int
    {
        $now = Carbon::now();

        if ($this->option('dry-run')) {
            return $this->dryRun($service, $eligibility, $now);
        }

        if (! config('chat_silence_removal.enabled')) {
            $this->line('Выключено (CHAT_SILENCE_REMOVAL_ENABLED=false). Для просмотра — --dry-run.');

            return self::SUCCESS;
        }

        $result = $service->propose($now);
        $this->info(sprintf(
            'Предложено: студентов %d, чатов %d, сообщений доставлено %d.',
            $result['users'],
            $result['chats'],
            $result['sent'],
        ));

        return self::SUCCESS;
    }

    private function dryRun(ChatSilenceRemovalService $service, ChatSilenceRemovalEligibility $eligibility, Carbon $now): int
    {
        $this->info(sprintf(
            'Сухой прогон на %s · молчит ≥ %d дн · урок старше %d дн · отправка %s',
            $now->format('d.m.Y H:i'),
            $eligibility->silenceDays(),
            $eligibility->graceDays(),
            config('chat_silence_removal.enabled') ? 'ВКЛЮЧЕНА' : 'выключена',
        ));

        $pending = $service->pendingProposals($now);
        $rows = [];
        foreach ($pending as $candidates) {
            foreach ($candidates as $c) {
                $rows[] = [
                    $this->subject($c),
                    $c->group->name,
                    $c->lastMessageAt?->format('d.m.Y') ?? 'не писал',
                    $c->lesson !== null ? 'блок '.$c->lesson->block_number.' · '.$c->lesson->lesson_date?->format('d.m.Y') : '—',
                ];
            }
        }

        $this->newLine();
        if ($rows === []) {
            $this->line('Предлагать сегодня некого.');
        } else {
            $this->line('<comment>Будет предложено ('.$pending->count().' студ.):</comment>');
            $this->table(['кто', 'чат', 'последнее сообщение', 'закрытый урок'], $rows);
        }

        $all = $eligibility->candidates(now: $now);
        $byBlocker = [];
        foreach ($all as $c) {
            foreach ($c->blockers as $b) {
                $byBlocker[$b] = ($byBlocker[$b] ?? 0) + 1;
            }
        }
        arsort($byBlocker);

        $this->newLine();
        $this->line('Участников в проверенных чатах: '.$all->count().'. Отсеяно по причинам:');
        foreach ($byBlocker as $b => $n) {
            $this->line(sprintf('  %4d  %s', $n, ChatSilenceRemovalEligibility::blockerLabel($b)));
        }

        foreach ($eligibility->skippedChats() as $chat => $reason) {
            $this->line('  чат '.$chat.' пропущен: '.ChatSilenceRemovalEligibility::blockerLabel($reason));
        }

        if ($this->option('all')) {
            $this->newLine();
            $this->table(
                ['кто', 'чат', 'почему нет'],
                $all->reject(fn (ChatSilenceCandidate $c) => $c->isEligible())
                    ->map(fn (ChatSilenceCandidate $c) => [
                        $this->subject($c),
                        $c->group->name,
                        implode('; ', array_map(ChatSilenceRemovalEligibility::blockerLabel(...), $c->blockers)),
                    ])->all(),
            );
        }

        return self::SUCCESS;
    }

    private function subject(ChatSilenceCandidate $c): string
    {
        return $this->option('reveal') ? $c->displayName() : $c->redactedSubject();
    }
}
