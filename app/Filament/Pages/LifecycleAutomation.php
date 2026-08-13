<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\Crm\Lifecycle\LifecycleCampaignPreparer;
use App\Services\Crm\Lifecycle\LifecycleEligibility;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * CRM Wave 2 (H2484) — dry-run counts + prepare drafts. Never sends.
 */
class LifecycleAutomation extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationGroup = 'Продажи';

    protected static ?int $navigationSort = 66;

    protected static ?string $navigationLabel = 'Lifecycle-кампании';

    protected static ?string $title = 'Lifecycle-кампании';

    protected static ?string $slug = 'lifecycle-campaigns';

    protected static string $view = 'filament.pages.lifecycle-automation';

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public bool $flagOn = false;

    public static function canAccess(): bool
    {
        return (bool) config('features.crm_lifecycle_automation')
            && RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->flagOn = (bool) config('features.crm_lifecycle_automation');
        $this->refreshCensus();
    }

    public function refreshCensus(): void
    {
        $this->rows = app(LifecycleCampaignPreparer::class)->run(apply: false)['rules'];
    }

    public function prepareDrafts(): void
    {
        abort_unless(static::canAccess(), 403);

        $result = app(LifecycleCampaignPreparer::class)->run(apply: true, withFollowUps: true);
        $this->rows = $result['rules'];

        $ids = collect($result['rules'])->pluck('campaign_id')->filter()->implode(', ');
        Notification::make()
            ->title('Черновики подготовлены, письма не отправлены')
            ->body($ids === '' ? 'Нет черновиков.' : 'Campaign id: '.$ids.'. Отправка — только кнопка «Отправить» в Email-кампаниях.')
            ->success()
            ->send();
    }

    /** @return list<string> */
    public function ruleKeys(): array
    {
        return LifecycleEligibility::rules();
    }
}
