<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\TeacherPayoutAttributionSuggestionResource;
use App\Models\TeacherPayoutAttributionSuggestion;
use App\Support\Money;
use App\Support\Plural;
use App\Support\RoleGate;
use Filament\Pages\Page;

/**
 * H3084 — инструкция «как размечать выплаты», ВНУТРИ кабинета.
 *
 * Ruling MG 18-08-2026: рабочие указания сотруднику видны ему в его кабинете,
 * а не выложены на всеобщее обозрение. Публичный репозиторий и публичная
 * issue-трекер-задача для этого не годятся: там фамилия преподавателя, суммы
 * и остаток по нему видны кому угодно.
 *
 * Поэтому страница НЕ повторяет текст из репозитория — она и есть источник.
 * Список платежей на ней строится из живой очереди, а не переписан руками:
 * инструкция, которая расходится с экраном, хуже отсутствующей.
 */
class PayoutAttributionGuide extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationGroup = 'Финансы';

    protected static ?string $navigationLabel = 'Как размечать выплаты';

    protected static ?int $navigationSort = 47;

    protected static ?string $title = 'Как размечать выплаты преподавателям';

    protected static ?string $slug = 'payout-attribution-guide';

    protected static string $view = 'filament.pages.payout-attribution-guide';

    /** Тот же гейт, что у очереди и у «Потоков курса». */
    public static function canAccess(): bool
    {
        return RoleGate::finance();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return RoleGate::finance();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $pending = TeacherPayoutAttributionSuggestion::query()
            ->pending()
            ->with(['payment:id,user_id', 'payment.user:id,name', 'course:id,title', 'teacher:id,name'])
            ->orderBy('paid_on')
            ->get();

        $byTeacher = [];
        foreach ($pending as $row) {
            $name = $row->teacher?->name ?? '—';
            $byTeacher[$name][] = $row;
        }

        return [
            'pending' => $pending,
            'byTeacher' => $byTeacher,
            'pendingTotal' => Money::round((float) $pending->sum(fn ($r): float => (float) $r->amount)),
            'pendingWord' => Plural::ru($pending->count(), 'платёж', 'платежа', 'платежей'),
            'queueUrl' => TeacherPayoutAttributionSuggestionResource::getUrl(),
            'streamsUrl' => CourseStreamComparison::getUrl(),
        ];
    }
}
