{{-- «Спасибо, ваш голос учтён!» — флеш ставит PublicWaitlistController::vote
     (кнопка на /online/zhdun, карточка каталога или страница курса; у гостя —
     CastPendingWaitlistVote после входа). Инклюдится на всех трёх поверхностях. --}}
@if(session(\App\Http\Controllers\Api\PublicWaitlistController::VOTED_FLASH_KEY))
    <div x-data="{ show: true }"
         x-init="setTimeout(() => show = false, 5000)"
         x-show="show"
         x-transition.opacity.duration.300ms
         role="status"
         data-waitlist-voted-toast
         class="fixed z-50 top-20 left-4 right-4 sm:left-auto sm:right-6 sm:w-96 flex items-center gap-3 rounded-2xl bg-[#111622] border border-emerald-500/40 shadow-2xl shadow-black/40 px-4 py-3">
        <span class="flex-none w-9 h-9 rounded-full bg-emerald-500 text-white flex items-center justify-center">
            <i class="fas fa-check"></i>
        </span>
        <p class="flex-1 text-sm font-bold text-white">Спасибо, ваш голос учтён!</p>
        <button type="button" x-on:click="show = false" title="Закрыть"
                class="flex-none text-slate-400 hover:text-white transition">
            <i class="fas fa-times"></i>
        </button>
    </div>
@endif
