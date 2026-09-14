<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\CabinetMasteryAttempt;
use App\Support\CabinetMastery;
use App\Support\RoleGate;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * H3244 — внутренний тест по книге бухгалтера.
 * Тонкая копия CabinetMasteryQuiz: другой audience и canAccess.
 * Гейт — существующий RoleGate::finance(), методы RoleGate не трогаем.
 */
class AccountantMasteryQuiz extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Финансы';

    protected static ?string $navigationParentItem = 'Как работать бухгалтеру';

    protected static ?string $navigationLabel = 'Проверка бухгалтера';

    protected static ?string $title = 'Проверка: как работать бухгалтеру';

    protected static ?string $slug = 'accountant-mastery';

    protected static ?int $navigationSort = 45;

    protected static string $view = 'filament.pages.cabinet-mastery-quiz';

    /** @var array<string, string> */
    public array $answers = [];

    /** @var array<string, mixed>|null */
    public ?array $result = null;

    public static function canAccess(): bool
    {
        return RoleGate::finance();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function submit(): void
    {
        $user = Auth::user();
        if ($user === null) {
            return;
        }

        $bank = CabinetMastery::bank(CabinetMastery::AUDIENCE_ACCOUNTANT);
        $ids = [];
        foreach ($bank['questions'] as $question) {
            $ids[] = 'answers.'.$question['id'];
        }
        $this->validate(
            array_fill_keys($ids, 'required|string'),
            ['required' => 'Отметьте вариант.'],
        );

        $graded = CabinetMastery::grade(CabinetMastery::AUDIENCE_ACCOUNTANT, $this->answers);

        CabinetMasteryAttempt::create([
            'user_id' => $user->id,
            'audience' => CabinetMastery::AUDIENCE_ACCOUNTANT,
            'score' => $graded['score'],
            'total' => $graded['total'],
            'passed' => $graded['passed'],
            'answers' => $this->answers,
        ]);

        $this->result = $graded;

        Notification::make()
            ->title($graded['passed']
                ? 'Зачёт: '.$graded['score'].' из '.$graded['total']
                : 'Пока '.$graded['score'].' из '.$graded['total'].' — порог '.$graded['pass'])
            ->{$graded['passed'] ? 'success' : 'warning'}()
            ->send();
    }

    public function resetQuiz(): void
    {
        $this->answers = [];
        $this->result = null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function questions(): array
    {
        $user = Auth::user();

        return CabinetMastery::questionsForDisplay(
            CabinetMastery::AUDIENCE_ACCOUNTANT,
            (int) ($user?->id ?? 0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function bank(): array
    {
        return CabinetMastery::bank(CabinetMastery::AUDIENCE_ACCOUNTANT);
    }
}
