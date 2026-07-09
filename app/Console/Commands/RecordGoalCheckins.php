<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Filament\Pages\GoalCheckins;
use App\Models\User;
use App\Services\GoalCheckinService;
use App\Support\Roles;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

/**
 * Еженедельный goal check-in (H376) — младший брат finance:kpi-digest, но для
 * целей делегированных лидов. Записывает check-in для каждой активной цели
 * (последним известным значением, без нового ввода — просто фиксирует
 * ухудшение/улучшение темпа относительно дедлайна день ото дня) и шлёт
 * дайджест при красном флаге.
 */
class RecordGoalCheckins extends Command
{
    protected $signature = 'goals:record-checkins
        {--dry : Только показать сводку, без записи check-in\'ов и без отправки уведомлений}';

    protected $description = 'Еженедельный check-in всех активных целей (запускать раз в неделю).';

    public function handle(GoalCheckinService $service): int
    {
        $snap = $service->snapshot();

        $this->line('Активных целей: '.$snap['goals']->count().' — общий статус: '.($snap['ok'] ? 'ok' : 'есть красные флаги'));
        foreach ($snap['goals'] as $card) {
            $this->line('  ['.$card['level'].'] '.$card['goal']->title.': '.$card['current_value'].' / '.(float) $card['goal']->target_value);
        }

        if ($this->option('dry')) {
            $this->comment('--dry: check-in\'ы не записаны, уведомления не отправлены.');

            return self::SUCCESS;
        }

        $service->recordCheckinsForAllActive();

        if ($snap['ok']) {
            $this->info('Красных флагов нет; уведомления не отправлены.');

            return self::SUCCESS;
        }

        $recipients = User::query()
            ->whereIn('role', [Roles::SUPER_ADMIN, Roles::ADMIN, Roles::ACCOUNTANT])
            ->get();

        foreach ($recipients as $recipient) {
            Notification::make()
                ->title('Есть цели, отстающие от темпа')
                ->body('Проверьте панель Check-in целей — как минимум одна цель ниже ожидаемого темпа.')
                ->danger()
                ->actions([
                    Action::make('open')
                        ->label('Открыть панель')
                        ->url(GoalCheckins::getUrl(), shouldOpenInNewTab: true),
                ])
                ->sendToDatabase($recipient);
        }

        $this->info('Дайджест отправлен получателям: '.$recipients->count());

        return self::SUCCESS;
    }
}
