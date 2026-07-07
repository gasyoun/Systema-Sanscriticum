<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Filament\Pages\ReceivablesGovernance;
use App\Models\User;
use App\Services\ReceivablesGovernanceService;
use App\Support\Roles;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

/**
 * Демон-алерт по дебиторке (H257, фаза C). Раз в день считает снимок
 * {@see ReceivablesGovernanceService} и, если контур в тревоге (пробит порог
 * «максимально допустимой дебиторки», растёт доля неликвида или пробит лимит
 * рассрочки), шлёт финдиру/бухгалтеру заметное уведомление в админку.
 *
 * Это и есть замена ручного мониторинга владельца: он не смотрит цифры каждый
 * день — система сама зовёт, когда дебиторка выходит из-под контроля.
 */
class CheckReceivablesThreshold extends Command
{
    protected $signature = 'receivables:check {--dry : Только показать вердикт, без отправки уведомлений}';

    protected $description = 'Проверка порога дебиторки и лимитов рассрочки; алерт финдиру при превышении (запускать раз в день).';

    public function handle(ReceivablesGovernanceService $service): int
    {
        $snap = $service->snapshot();

        if ($snap['ok']) {
            $this->info('Дебиторка под контролем: '.$snap['total'].' ₽ из '.$snap['threshold'].' ₽. Алерт не нужен.');

            return self::SUCCESS;
        }

        $this->warn('Дебиторка вне нормы. Причины:');
        foreach ($snap['alerts'] as $alert) {
            $this->line('  • '.$alert);
        }

        if ($this->option('dry')) {
            $this->comment('--dry: уведомления не отправлены.');

            return self::SUCCESS;
        }

        $recipients = User::query()
            ->whereIn('role', [Roles::SUPER_ADMIN, Roles::ADMIN, Roles::ACCOUNTANT])
            ->get();

        if ($recipients->isEmpty()) {
            $this->error('Нет получателей с ролью финдира/бухгалтера — уведомление не отправлено.');

            return self::FAILURE;
        }

        $body = implode(' ', $snap['alerts']);
        foreach ($recipients as $recipient) {
            Notification::make()
                ->title('Дебиторка вышла из-под контроля')
                ->danger()
                ->body($body)
                ->actions([
                    Action::make('open')
                        ->label('Открыть план-факт')
                        ->url(ReceivablesGovernance::getUrl(), shouldOpenInNewTab: true),
                ])
                ->sendToDatabase($recipient);
        }

        $this->info('Уведомление отправлено получателям: '.$recipients->count());

        return self::SUCCESS;
    }
}
