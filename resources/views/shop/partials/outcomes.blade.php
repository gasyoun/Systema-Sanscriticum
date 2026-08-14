{{-- «Чему вы научитесь» — courses.outcomes, иначе flagship overlay (H2761). --}}
@php
    $outcomes = collect($course->outcomes ?? [])->filter(fn ($x) => filled($x))->values();
    if ($outcomes->isEmpty() && ! empty($flagship['outcomes'])) {
        $outcomes = collect($flagship['outcomes']);
    }
@endphp
@if($outcomes->isNotEmpty())
<section id="chemu-nauchites" class="mb-16 lg:mb-20" data-analytics="outcomes">
    <h2 class="text-3xl font-bold text-white mb-8">Чему вы научитесь</h2>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-4">
        @foreach($outcomes as $item)
            <div class="flex items-start gap-3">
                <span class="flex items-center justify-center w-7 h-7 rounded-lg bg-[#38BDF8]/15 border border-[#38BDF8]/30 shrink-0 mt-0.5">
                    <i class="fas fa-check text-[#38BDF8] text-xs"></i>
                </span>
                <p class="text-slate-300 leading-relaxed">{{ $item }}</p>
            </div>
        @endforeach
    </div>
</section>
@endif
