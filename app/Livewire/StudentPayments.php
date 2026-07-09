<?php

namespace App\Livewire;

use App\Models\Course;
use App\Models\Payment;
use App\Services\StudentDebtsService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class StudentPayments extends Component
{
    use WithPagination;

    public function render()
    {
        $user = Auth::user();

        // Подтягиваем платежи вместе с данными КУРСА (а не лендинга)
        $payments = Payment::with('course')
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        // «Оплачено до» по каждому курсу, где у пользователя есть хоть один
        // реальный платёж (не только из текущей страницы пагинации) — сводка,
        // отвечающая «сколько оплатил и до какого момента я покрыт».
        $paidCourseIds = Payment::where('user_id', $user->id)
            ->whereIn('status', Payment::PAID_STATUSES)
            ->where('is_conditional', false)
            ->pluck('course_id')
            ->unique();
        $paidUntilByCourseId = app(StudentDebtsService::class)->paidUntilForUser($user, $paidCourseIds);
        $coursesById = Course::whereIn('id', $paidUntilByCourseId->keys())->get(['id', 'title'])->keyBy('id');

        return view('livewire.student-payments', [
            'payments' => $payments,
            'paidUntilByCourseId' => $paidUntilByCourseId,
            'coursesById' => $coursesById,
        ]);
    }
}
