<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Course;
use App\Models\Tariff;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Pages\Page;

/**
 * Доска «Ссылки PayPal» (MG 02-09-2026): для каждой живой или предстоящей
 * группы — прямая ссылка на страницу курса и кликабельные /paypal/{tariff}
 * self-serve формы по каждому активному тарифу с фиксированной иностранной
 * ценой (EUR/USD из tariff_foreign_prices). Куратор копирует ссылку для
 * ученика одним кликом, не глядя в базу.
 */
class PaypalLinksBoard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationLabel = 'Ссылки PayPal';

    protected static ?string $navigationGroup = 'Пользователи';

    protected static ?int $navigationSort = 76;

    protected static ?string $title = 'Ссылки PayPal по группам';

    protected static ?string $slug = 'paypal-links';

    protected static string $view = 'filament.pages.paypal-links';

    public static function canAccess(): bool
    {
        // MG 02-09-2026: куратор (manager) — основной адресат доски, он ведёт
        // переписку с учениками об оплате; см. прецедент H3764 (learningAnalytics).
        return RoleGate::any(Roles::ADMIN, Roles::ACCOUNTANT, Roles::MANAGER);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    /** @return array<int, array{course:Course,url:string,tariffs:array<int,array{id:int,title:string,rub:string,eur:?string,usd:?string,link:string}>,total_eur:?string,total_usd:?string}> */
    public function getGroupsProperty(): array
    {
        $appUrl = rtrim((string) config('app.url'), '/');

        $courses = Course::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('is_completed', false)->orWhereNull('is_completed');
            })
            ->whereHas('tariffs', fn ($q) => $q->where('is_active', true))
            ->with([
                'tariffs' => fn ($q) => $q->where('is_active', true)->orderBy('block_number')->orderBy('id'),
                'tariffs.foreignPrices',
            ])
            ->orderBy('title')
            ->get();

        $groups = [];
        foreach ($courses as $course) {
            $tariffRows = [];
            $totalEur = 0.0;
            $totalUsd = 0.0;
            $hasTotal = false;

            foreach ($course->tariffs as $tariff) {
                $eur = $this->foreignPrice($tariff, 'EUR');
                $usd = $this->foreignPrice($tariff, 'USD');
                $isBlock = $tariff->type === 'block';

                if ($isBlock) {
                    if ($eur !== null) {
                        $totalEur += (float) $eur;
                        $hasTotal = true;
                    }
                    if ($usd !== null) {
                        $totalUsd += (float) $usd;
                        $hasTotal = true;
                    }
                }

                $tariffRows[] = [
                    'id' => $tariff->id,
                    'title' => $tariff->title,
                    'rub' => number_format((float) $tariff->price, 0, '.', ' '),
                    'eur' => $eur,
                    'usd' => $usd,
                    'link' => $appUrl.'/paypal/'.$tariff->id,
                ];
            }

            $groups[] = [
                'course' => $course,
                'url' => $appUrl.'/course/'.$course->slug,
                'tariffs' => $tariffRows,
                'total_eur' => $hasTotal && $totalEur > 0 ? number_format($totalEur, 0, '.', ' ') : null,
                'total_usd' => $hasTotal && $totalUsd > 0 ? number_format($totalUsd, 0, '.', ' ') : null,
            ];
        }

        return $groups;
    }

    private function foreignPrice(Tariff $tariff, string $currency): ?string
    {
        $row = $tariff->foreignPrices->firstWhere('currency', $currency);

        return $row ? number_format((float) $row->price, 0, '.', ' ') : null;
    }
}
