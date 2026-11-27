<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\StudentAvailabilityWindow as Window;
use App\Support\StudentAvailability;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * Вкладка /dvaram/availability (26-11-2026): студент сам задаёт недельные окна,
 * в которые ему можно предлагать новые курсы. За флагом availability_windows.
 */
class StudentAvailabilityController extends Controller
{
    public function index(): View
    {
        $this->gate();
        $user = auth()->user();

        return view('student.availability', [
            'windows' => Window::query()
                ->where('user_id', $user->id)
                ->where('source', Window::SOURCE_STUDENT)
                ->orderBy('id')->get(),
            'tzid' => app(StudentAvailability::class)->timezoneFor($user),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->gate();
        $user = auth()->user();

        $data = Validator::make($request->all(), [
            'days' => ['required', 'array', 'max:7'],
            'days.*' => ['array', 'max:2'],
            'days.*.*.enabled' => ['sometimes', 'boolean'],
            'days.*.*.kind' => ['required_with:days.*.*.enabled', 'in:'.Window::KIND_ALLOW.','.Window::KIND_DENY],
            'days.*.*.start' => ['required_with:days.*.*.enabled', 'date_format:H:i'],
            'days.*.*.end' => ['required_with:days.*.*.enabled', 'date_format:H:i'],
            'tzid' => ['required', 'string', 'timezone'],
        ])->validate();

        // Полная замена только студенческих строк; строки куратора не трогаем.
        Window::query()->where('user_id', $user->id)
            ->where('source', Window::SOURCE_STUDENT)->delete();

        foreach ($data['days'] as $day => $slots) {
            if (! in_array($day, Window::WEEKDAYS, true)) {
                continue;
            }
            foreach ($slots as $values) {
                if (empty($values['enabled'])) {
                    continue;
                }
                Window::query()->create([
                    'user_id' => $user->id,
                    'source' => Window::SOURCE_STUDENT,
                    'kind' => $values['kind'] ?? Window::KIND_ALLOW,
                    'rrule' => 'FREQ=WEEKLY;BYDAY='.$day,
                    'start_time' => $values['start'],
                    'end_time' => $values['end'],
                    'tzid' => $data['tzid'],
                ]);
            }
        }

        return redirect()->route('student.availability')
            ->with('bot_status', 'Окна доступности сохранены.');
    }

    private function gate(): void
    {
        abort_unless(config('features.availability_windows'), 404);
    }
}
