{{-- Гость нажал «Намерен участвовать» на /online/zhdun: голос ждёт входа (CastPendingWaitlistVote). --}}
@if (session()->has(\App\Http\Controllers\Api\PublicWaitlistController::PENDING_VOTE_SESSION_KEY))
    <div class="mb-6 bg-green-50 border border-green-200 text-green-700 rounded-xl px-4 py-3 text-sm font-medium flex items-start gap-2"
         data-pending-waitlist-vote>
        <i class="fas fa-check-circle mt-0.5"></i>
        <span>Войдите или зарегистрируйтесь — ваш голос в списке ожидания учтём автоматически.</span>
    </div>
@endif
