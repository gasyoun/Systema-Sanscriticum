{{-- «Для кого» / «кому не подходит» — courses.audience, иначе flagship overlay (H2761). --}}
@php
    $audience = collect($course->audience ?? [])->filter(fn ($x) => filled($x))->values();
    if ($audience->isEmpty() && ! empty($flagship['audience'])) {
        $audience = collect($flagship['audience']);
    }
    $unfit = collect($flagship['unfit'] ?? [])->filter(fn ($x) => filled($x))->values();
@endphp
@if($audience->isNotEmpty() || $unfit->isNotEmpty())
<section id="komu-podhodit" class="mb-16 lg:mb-20" data-analytics="audience">
    <h2 class="text-3xl font-bold text-white mb-8">Для кого этот курс</h2>
    @if($audience->isNotEmpty())
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @foreach($audience as $item)
                <div class="flex items-start gap-3 rounded-2xl bg-[#111622] border border-[#1F2636] p-5">
                    <i class="fas fa-user-check text-brand mt-1 shrink-0"></i>
                    <p class="text-slate-300 leading-relaxed">{{ $item }}</p>
                </div>
            @endforeach
        </div>
    @endif
    @if($unfit->isNotEmpty())
        <h3 class="text-xl font-bold text-white mt-10 mb-5">Кому не подходит</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @foreach($unfit as $item)
                <div class="flex items-start gap-3 rounded-2xl bg-[#111622] border border-[#1F2636] p-5">
                    <i class="fas fa-user-times text-slate-500 mt-1 shrink-0"></i>
                    <p class="text-slate-300 leading-relaxed">{{ $item }}</p>
                </div>
            @endforeach
        </div>
    @endif
</section>
@endif
