<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\Payroll\PayrollReadinessService;
use App\Support\RoleGate;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class TeacherPayrollReadiness extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Финансы';

    protected static ?int $navigationSort = 73;

    protected static ?string $navigationLabel = 'Готовность выплат';

    protected static ?string $title = 'Готовность выплат преподавателям';

    protected static ?string $slug = 'teacher-payroll-readiness';

    protected static string $view = 'filament.pages.teacher-payroll-readiness';

    public string $cutoff = '';

    public string $expectedFingerprint = '';

    public ?bool $fingerprintMatches = null;

    public static function canAccess(): bool
    {
        return (bool) config('features.teacher_payroll_readiness') && RoleGate::accounting();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->cutoff = (string) config('payroll_readiness.target_date');
    }

    /** @return array<string, mixed> */
    public function getReport(): array
    {
        return app(PayrollReadinessService::class)->build(Carbon::parse($this->cutoff));
    }

    public function verifyFingerprint(): void
    {
        $live = $this->getReport();
        $this->fingerprintMatches = $this->expectedFingerprint !== ''
            && hash_equals($this->expectedFingerprint, (string) $live['fingerprint']);
        Notification::make()
            ->title($this->fingerprintMatches ? 'Отпечаток совпадает' : 'Отпечаток устарел — строку нельзя переводить')
            ->color($this->fingerprintMatches ? 'success' : 'danger')
            ->send();
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('private_export')
                ->label('Скачать приватный JSON')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(function (): StreamedResponse {
                    $report = $this->getReport();
                    $name = 'teacher-payroll-readiness-'.Carbon::parse($this->cutoff)->format('Ymd-His').'.json';

                    return response()->streamDownload(
                        fn () => print json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
                        $name,
                        ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-store, private'],
                    );
                }),
            AccountantGuide::openAction(),
        ];
    }
}
