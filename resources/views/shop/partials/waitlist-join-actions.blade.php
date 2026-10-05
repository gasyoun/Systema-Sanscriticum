{{--  — общие действия ждун-строки: голосование обычной POST-формой
     (без JS — работает и внутри Livewire-каталога), отзыв голоса, ссылка
     при открытой оплате. Словарь и состояния — как на /online/zhdun.
     item — CourseWaitlistItem с withCount('votes'); voted/myPref — состояние зрителя. --}}
@props(['item', 'voted' => false, 'myPref' => null])

@if($voted)
    <form method="POST" action="{{ route('shop.waitlist.unvote') }}">
        @csrf
        <input type="hidden" name="slug" value="{{ $item->slug }}">
        <button type="submit" data-testid="waitlist-unvote" title="Отозвать голос"
                class="flex justify-center items-center w-full py-2.5 px-4 bg-transparent border border-emerald-500/30 hover:border-emerald-500/60 text-emerald-400 text-[11px] font-bold rounded-xl transition-all">
            <i class="fas fa-check mr-2"></i>
            Голос учтен@if($myPref && isset(\App\Models\WaitlistVote::SLOT_PREFERENCES[$myPref])) · {{ \App\Models\WaitlistVote::SLOT_PREFERENCES[$myPref] }}@endif
            <i class="fas fa-times ml-2 opacity-60"></i>
        </button>
    </form>
@elseif($item->status === \App\Models\CourseWaitlistItem::STATUS_PAYMENT_OPEN)
    @if($item->course)
        <a href="{{ route('shop.course.show', $item->course->slug) }}#tariffs"
           data-testid="waitlist-payment-open"
           class="flex justify-center items-center w-full py-2.5 px-4 bg-emerald-600 hover:bg-emerald-500 text-white text-[11px] font-bold rounded-xl transition-all">
            Открыта оплата — к курсу
        </a>
    @else
        <span class="block text-center text-[11px] font-bold text-emerald-400 bg-emerald-500/10 px-3 py-2 rounded-xl">
            Открыта оплата — свяжитесь с куратором
        </span>
    @endif
@else
    <form method="POST" action="{{ route('shop.waitlist.vote') }}" data-testid="waitlist-vote-form">
        @csrf
        <input type="hidden" name="slug" value="{{ $item->slug }}">
        {{-- Пожелание времени — только когда слот ещё не решён (MG 24-09-2026). --}}
        @if(! $item->slot)
            <select name="slot_preference" title="Когда вам удобно?"
                    class="w-full mb-2 text-xs font-semibold text-slate-300 bg-[#141A28] border border-[#1F2636] hover:border-brand/50 rounded-lg px-2 py-2">
                <option value="">Когда удобно?</option>
                @foreach(\App\Models\WaitlistVote::SLOT_PREFERENCES as $prefKey => $prefLabel)
                    <option value="{{ $prefKey }}">{{ $prefLabel }}</option>
                @endforeach
            </select>
        @endif
        <button type="submit" data-testid="waitlist-vote"
                class="flex justify-center items-center w-full py-2.5 px-4 bg-brand hover:opacity-90 text-white text-[11px] font-bold rounded-xl transition-all">
            <i class="fas fa-hand-raised mr-2"></i>
            Намерен участвовать
        </button>
    </form>
@endif
