<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\CabinetMasteryAttempt;
use App\Support\CabinetMastery;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * H3215 — внутренний тест усвоения кабинета для куратора.
 * Гейт объявлен здесь: существующие canViewAny не трогаем.
 */
class CabinetMasteryQuiz extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Обучение';

    protected static ?string $navigationLabel = 'Проверка кабинета';

    protected static ?string $title = 'Проверка: кабинет глазами куратора';

    protected static ?string $slug = 'cabinet-mastery';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.cabinet-mastery-quiz';

    /** @var array<string, string> */
    public array $answers = [];

    /** @var array<string, mixed>|null */
    public ?array $result = null;

    public static function canAccess(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
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

        $bank = CabinetMastery::bank(CabinetMastery::AUDIENCE_CURATOR);
        $ids = [];
        foreach ($bank['questions'] as $question) {
            $ids[] = 'answers.'.$question['id'];
        }
        $this->validate(
            array_fill_keys($ids, 'required|string'),
            ['required' => 'Отметьте вариант.'],
        );

        $graded = CabinetMastery::grade(CabinetMastery::AUDIENCE_CURATOR, $this->answers);

        CabinetMasteryAttempt::create([
            'user_id' => $user->id,
            'audience' => CabinetMastery::AUDIENCE_CURATOR,
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
            CabinetMastery::AUDIENCE_CURATOR,
            (int) ($user?->id ?? 0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function bank(): array
    {
        return CabinetMastery::bank(CabinetMastery::AUDIENCE_CURATOR);
    }
}
