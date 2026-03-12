<?php

namespace App\Livewire;

use App\Models\Payment;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class StudentPayments extends Component
{
    use WithPagination;

    public function render()
    {
        // Подтягиваем платежи вместе с данными КУРСА (а не лендинга)
        $payments = Payment::with('course')
            ->where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return view('livewire.student-payments', [
            'payments' => $payments,
        ]);
    }
}