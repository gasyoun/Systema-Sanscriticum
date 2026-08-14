{{-- «FAQ по курсу» — course->faqs, иначе flagship overlay (H2761). --}}
@php
    $dbFaqs = ($course->faqs ?? collect())->where('is_visible', true)->values();
    $faqs = $dbFaqs->isNotEmpty()
        ? $dbFaqs->map(fn ($faq) => ['question' => $faq->question, 'answer' => $faq->answer])->values()
        : collect($flagship['faqs'] ?? [])->map(fn ($faq) => [
            'question' => $faq['q'] ?? $faq['question'] ?? '',
            'answer' => $faq['a'] ?? $faq['answer'] ?? '',
        ])->filter(fn ($faq) => filled($faq['question']))->values();
@endphp
@if($faqs->isNotEmpty())
<section id="course-faq" class="mb-16 lg:mb-20" data-analytics="faq" x-data="{ open: null }">
    <h2 class="text-3xl font-bold text-white mb-8">Частые вопросы</h2>

    <div class="space-y-3 max-w-3xl">
        @foreach($faqs as $i => $faq)
            <div class="rounded-2xl bg-[#111622] border border-[#1F2636] overflow-hidden">
                <button type="button"
                        @click="open === {{ $i }} ? open = null : open = {{ $i }}"
                        data-analytics="faq-expand"
                        class="w-full flex items-center justify-between gap-4 p-5 text-left hover:bg-[#1A2235] transition-colors">
                    <span class="text-base font-bold text-white">{{ $faq['question'] }}</span>
                    <i class="fas fa-chevron-down text-slate-500 text-sm transition-transform shrink-0"
                       :class="open === {{ $i }} ? 'rotate-180' : ''"></i>
                </button>
                <div x-show="open === {{ $i }}" x-transition style="display:none" class="border-t border-[#1F2636]">
                    <div class="px-5 py-4 text-slate-300 leading-relaxed whitespace-pre-line">{{ $faq['answer'] }}</div>
                </div>
            </div>
        @endforeach
    </div>
</section>
@endif
