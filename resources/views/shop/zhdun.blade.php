@extends('layouts.shop')

@section('title', $page->title)

@section('content')
<div class="min-h-screen bg-[#0A0D14] text-white py-16 lg:py-24 relative overflow-hidden font-sans"
     x-data="waitlistVote()">

    <div class="absolute top-0 left-1/4 w-96 h-96 bg-brand/10 rounded-full blur-[120px] pointer-events-none"></div>
    <div class="absolute bottom-1/4 right-0 w-[500px] h-[500px] bg-indigo-500/10 rounded-full blur-[150px] pointer-events-none"></div>

    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-12 relative z-10">

        <header class="text-center mb-14">
            <p class="text-sm font-semibold tracking-wide text-[#38BDF8] mb-4">Набор в новые группы</p>
            <h1 class="text-4xl md:text-5xl font-extrabold text-white tracking-tight mb-6">
                Список ожидания
            </h1>
            <p class="text-lg md:text-xl text-slate-400 max-w-3xl mx-auto leading-relaxed">
                За какой курс голосовать? Голосуйте за будущие группы при
                «Обществе ревнителей санскрита»: наберётся необходимый минимум
                голосов — откроется оплата; нужное число оплат к сроку — группа
                стартует.
            </p>
        </header>

        @if($items->isEmpty())
            <div class="text-center rounded-2xl bg-[#111622] border border-[#1F2636] p-10">
                <p class="text-slate-400 mb-4">Сейчас список пуст — заглядывайте позже.</p>
                <a href="{{ route('shop.index') }}"
                   class="inline-flex items-center gap-2 px-6 py-3 bg-brand hover:bg-brand/85 text-white text-sm font-bold rounded-xl transition-all">
                    Весь каталог курсов
                </a>
            </div>
        @else
            <div class="grid gap-4 md:grid-cols-2" data-analytics="waitlist-grid">
                @foreach($items as $item)
                    @php
                        $already = in_array($item->getKey(), $votedItemIds, true);
                        $met = (int) $item->votes_count >= $item->min_payers;
                        $paymentOpen = $item->status === \App\Models\CourseWaitlistItem::STATUS_PAYMENT_OPEN;
                    @endphp
                    <div class="flex flex-col rounded-2xl bg-[#111622] border border-[#1F2636] hover:border-brand/50 p-5 transition-all"
                         data-waitlist-row="{{ $item->slug }}">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h2 class="text-base font-bold text-white leading-snug">{{ $item->course_title }}</h2>
                                <p class="text-xs text-slate-500 mt-1">
                                    {{ $item->teacher_name }}
                                    @if($item->slot) · {{ $item->slot }} @endif
                                    @if($item->earliest_start_at)
                                        · не раньше {{ $item->earliest_start_at->format('d.m.Y') }}
                                    @endif
                                </p>
                            </div>
                            @if($item->block_price_rub)
                                <span class="text-xs font-bold text-slate-200 whitespace-nowrap bg-[#1F2636] rounded-lg px-2 py-1">
                                    {{ number_format($item->block_price_rub, 0, ',', ' ') }} ₽
                                </span>
                            @endif
                        </div>

                        <div class="flex items-center justify-between mt-auto pt-4">
                            <span class="text-xs font-semibold {{ $met ? 'text-emerald-400' : 'text-slate-400' }}"
                                  data-waitlist-progress="{{ $item->slug }}">
                                Голосов: <span data-waitlist-count>{{ $item->votes_count }}</span> из {{ $item->min_payers }}
                                @if($met) <i class="fas fa-check-circle ml-1"></i> @endif
                            </span>

                            @if($already)
                                <span class="text-xs font-bold text-emerald-400"><i class="fas fa-check mr-1"></i>Голос учтён</span>
                            @elseif($paymentOpen)
                                @if($item->course)
                                    <a href="{{ route('shop.course.show', $item->course->slug) }}"
                                       class="text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-500 transition rounded-lg px-3 py-1.5">
                                        Открыта оплата — к курсу
                                    </a>
                                @else
                                    <span class="text-xs font-bold text-emerald-400 bg-emerald-500/10 px-2 py-1 rounded-lg">Оплата открыта — ждём администратора</span>
                                @endif
                            @else
                                <button type="button"
                                        data-waitlist-vote="{{ $item->slug }}"
                                        class="text-xs font-bold text-white bg-brand hover:opacity-90 transition rounded-lg px-3 py-1.5"
                                        x-on:click="vote('{{ $item->slug }}', $el)">
                                    Голосовать
                                </button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        <section class="mt-12 mb-8 text-center">
            <p class="text-slate-400 mb-4">Ищете курс, который уже идёт?</p>
            <a href="{{ route('shop.index') }}"
               class="inline-flex items-center gap-2 px-6 py-3 bg-[#141A28] border border-[#1F2636] hover:border-brand/60 hover:bg-brand/5 text-white text-sm font-bold rounded-xl transition-all">
                Весь каталог курсов
            </a>
        </section>

    </div>
</div>

<script>
function waitlistVote() {
    return {
        async vote(slug, el) {
            el.disabled = true;
            el.textContent = '…';
            try {
                const resp = await fetch('/api/public/waitlist/vote', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ slug }),
                });
                if (resp.status === 401) { window.location.href = '/login'; return; }
                const data = await resp.json();
                const row = el.closest('[data-waitlist-row]');
                if (data.ok) {
                    const count = row?.querySelector('[data-waitlist-count]');
                    if (count) count.textContent = data.votes;
                    el.outerHTML = '<span class="text-xs font-bold text-emerald-400"><i class="fas fa-check mr-1"></i>Голос учтён</span>';
                } else {
                    el.textContent = 'Не вышло';
                }
            } catch (e) {
                el.textContent = 'Ошибка сети';
            }
        },
    };
}
</script>
@endsection