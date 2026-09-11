{{-- session('error'|'success') — self-service долг, CSRF 419, перенос даты.
     Единый партиал для легаси-дашборда и hybrid-страниц: DebtPaymentController
     пишет flash и редиректит в кабинет; страница без этого блока превращает
     любую отбивку (например анти-дубль оплаты) в «кнопка ничего не делает». --}}
@if (session('error'))
    <div x-data="{ show: true }" x-show="show" role="alert"
         class="mb-6 flex items-center justify-between gap-3 bg-red-50 border border-red-200 text-red-700 text-sm rounded-xl px-4 py-3">
        <span><i class="fas fa-triangle-exclamation mr-1.5"></i>{{ session('error') }}</span>
        <button type="button" x-on:click="show = false" class="text-red-500 hover:text-red-700" aria-label="Закрыть"><i class="fas fa-times"></i></button>
    </div>
@endif

@if (session('success'))
    <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 8000)" role="status"
         class="mb-6 flex items-center justify-between gap-3 bg-green-50 border border-green-200 text-green-700 text-sm rounded-xl px-4 py-3">
        <span><i class="fas fa-check-circle mr-1.5"></i>{{ session('success') }}</span>
        <button type="button" x-on:click="show = false" class="text-green-500 hover:text-green-700" aria-label="Закрыть"><i class="fas fa-times"></i></button>
    </div>
@endif
