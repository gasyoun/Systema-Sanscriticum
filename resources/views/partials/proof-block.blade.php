{{--
    Proof-блок (H431, Phase 1 п.3): жёсткие цифры (config/trust.php,
    env-backed, никогда не хардкодить) + слоты отзывов. Слоты отзывов
    подключены к реальной библиотеке Testimonial::featured() (та же полоса,
    что и на витрине магазина) — пока сборщик отзывов (@DO) не закрыт,
    слотов там либо мало, либо нет, и вместо выдуманных цитат честно
    показывается заглушка «скоро появятся», а не фейковый контент.
--}}
@php
    $testimonials = $testimonials ?? null;
    $sinceYear = (int) config('trust.since_year');
    $yearsCommunity = max(1, now()->year - $sinceYear);
    $booksPublished = config('trust.books_published');
    $booksPages = config('trust.books_pages');
    $crowdfundingRub = config('trust.crowdfunding_raised_rub');
    $patronTopupRub = config('trust.crowdfunding_patron_topup_rub');
    $graduatesCount = config('trust.graduates_count');

    $proofNumbers = array_values(array_filter([
        [
            'value' => $yearsCommunity.'+',
            'label' => 'лет сообществу «Общество ревнителей санскрита» (с '.$sinceYear.' года)',
        ],
        $booksPublished ? [
            'value' => $booksPublished.'+',
            'label' => 'изданных книг серии Bibliotheca Sanscritica'
                .($booksPages ? ' ('.number_format($booksPages, 0, '.', ' ').' стр.)' : ''),
        ] : null,
        $crowdfundingRub ? [
            'value' => number_format((int) round($crowdfundingRub / 1000), 0, '.', ' ').' тыс. ₽',
            'label' => 'собрано краудфандингом на издание книг'
                .($patronTopupRub ? ' + '.number_format((int) round($patronTopupRub / 1000), 0, '.', ' ').' тыс. ₽ от меценатов' : ''),
        ] : null,
        $graduatesCount ? [
            'value' => number_format($graduatesCount, 0, '.', ' ').'+',
            'label' => 'учеников прошло наши курсы и занятия',
        ] : null,
    ]));
@endphp
<section class="mb-16 lg:mb-20" data-analytics="proof-block">
    <div class="grid grid-cols-2 lg:grid-cols-{{ count($proofNumbers) === 3 ? 3 : 4 }} gap-4 mb-8">
        @foreach($proofNumbers as $item)
            <div class="text-center rounded-2xl bg-[#111622] border border-[#1F2636] p-5">
                <div class="text-2xl md:text-3xl font-extrabold text-brand mb-1">{{ $item['value'] }}</div>
                <p class="text-xs md:text-sm text-slate-400 leading-snug">{{ $item['label'] }}</p>
            </div>
        @endforeach
    </div>

    @if($testimonials && $testimonials->count() > 0)
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($testimonials as $t)
                <div class="rounded-2xl bg-[#111622] border border-[#1F2636] p-5">
                    <p class="text-sm text-slate-300 leading-relaxed mb-3">&laquo;{{ Str::limit($t->body, 220) }}&raquo;</p>
                    <div class="text-sm font-bold text-white">{{ $t->author_name }}</div>
                    @if($t->city)
                        <div class="text-xs text-slate-500">{{ $t->city }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    @else
        <div class="text-center py-10 bg-gray-800/40 rounded-2xl border border-gray-700/60">
            <p class="text-gray-400 text-sm">Скоро здесь появятся отзывы наших учеников.</p>
        </div>
    @endif
</section>
