<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * House pager for scheduled money commands (H2338 / audit spec 7).
 *
 * Laravel's schedule alone only logs failures into laravel.log; without a
 * callback, operators never get a push signal. This reporter is the house
 * equivalent of onFailure/pingOnFailure:
 *
 * 1. Log::critical — greppable, lands in production log shipping.
 * 2. Filament database notification to finance roles — visible in admin bell
 *    without needing a separate healthchecks check URL.
 *
 * Fail-open on the notification path: a broken admin notify must never
 * re-throw into the schedule runner.
 */
final class ScheduleFailureSignal
{
    public static function report(string $command): void
    {
        Log::critical('schedule.money_command_failed', [
            'command' => $command,
            'hint' => 'Inspect laravel.log around this timestamp; re-run the command manually; see docs/money-access-core-manual.md §11.7.',
        ]);

        try {
            $recipients = User::query()
                ->whereIn('role', [Roles::SUPER_ADMIN, Roles::ADMIN, Roles::ACCOUNTANT])
                ->get();

            if ($recipients->isEmpty()) {
                return;
            }

            foreach ($recipients as $recipient) {
                Notification::make()
                    ->title('Сбой денежного cron')
                    ->danger()
                    ->body(
                        "Команда `{$command}` завершилась с ошибкой. ".
                        'Смотрите laravel.log и docs/money-access-core-manual.md §11.7.'
                    )
                    ->sendToDatabase($recipient);
            }
        } catch (Throwable $e) {
            Log::warning('ScheduleFailureSignal: admin notify failed', [
                'command' => $command,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
