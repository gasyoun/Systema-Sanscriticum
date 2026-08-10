{{-- «Технические требования + как проходит оплата». Техтребования — общий текст
     или per-course override (Course::techRequirements()). Шаги оплаты статичны. --}}
<section class="mb-16 lg:mb-20" data-analytics="tech-payment">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        {{-- Технические требования --}}
        <div class="rounded-2xl bg-[#111622] border border-[#1F2636] p-6 lg:p-8">
            <div class="flex items-center gap-3 mb-5">
                <div class="w-10 h-10 rounded-xl bg-[#1F2636] flex items-center justify-center shrink-0">
                    <i class="fas fa-laptop text-[#38BDF8]"></i>
                </div>
                <h3 class="text-xl font-bold text-white">Технические требования</h3>
            </div>
            <p class="text-sm text-slate-400 leading-relaxed whitespace-pre-line">{{ $course->techRequirements() }}</p>
        </div>

        {{-- Как проходит оплата --}}
        <div class="rounded-2xl bg-[#111622] border border-[#1F2636] p-6 lg:p-8">
            <div class="flex items-center gap-3 mb-5">
                <div class="w-10 h-10 rounded-xl bg-[#1F2636] flex items-center justify-center shrink-0">
                    <i class="fas fa-credit-card text-brand"></i>
                </div>
                <h3 class="text-xl font-bold text-white">Как проходит оплата</h3>
            </div>
            <ol class="space-y-4">
                @php
                    $steps = [
                        'Выбираете тариф — весь курс, модуль или половину модуля.',
                        'Оплачиваете картой онлайн на защищенной странице.',
                        'Доступ к материалам открывается автоматически сразу после оплаты.',
                        'Заходите в личный кабинет и начинаете учиться.',
                    ];
                @endphp
                @foreach($steps as $i => $step)
                    <li class="flex items-start gap-3">
                        <span class="flex items-center justify-center w-7 h-7 rounded-lg bg-brand/15 border border-brand/30 text-brand text-sm font-black shrink-0">
                            {{ $i + 1 }}
                        </span>
                        <span class="text-sm text-slate-300 leading-relaxed pt-0.5">{{ $step }}</span>
                    </li>
                @endforeach
            </ol>
        </div>
    </div>
</section>
