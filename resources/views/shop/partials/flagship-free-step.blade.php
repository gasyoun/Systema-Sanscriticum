{{-- Per-track first step (H2761). Evergreen or «осень 2027» — never a stale 2026 start. --}}
@php $step = $flagship['free_step'] ?? null; @endphp
@if(! empty($step) && filled($step['title'] ?? null))
<section id="flagship-free-step"
         data-analytics="flagship-free-step"
         data-free-step-kind="{{ $step['kind'] }}"
         class="mb-16 lg:mb-20">
    <div class="rounded-2xl border border-brand/30 bg-gradient-to-r from-brand/10 to-transparent p-5 lg:p-6 flex flex-col md:flex-row md:items-center gap-4">
        <div class="flex-1 min-w-0">
            <div class="text-[10px] font-black uppercase tracking-widest text-brand mb-1.5">
                {{ $step['eyebrow'] }}
            </div>
            <h2 class="text-lg lg:text-xl font-bold text-white mb-1">{{ $step['title'] }}</h2>
            <p class="text-sm text-slate-400 leading-relaxed">{{ $step['body'] }}</p>
        </div>
        <a href="{{ $step['cta_url'] }}"
           data-analytics="flagship-free-step-cta"
           class="md:flex-shrink-0 inline-flex justify-center items-center py-3 px-5 bg-brand hover:bg-brand-hover text-white text-sm font-bold rounded-xl transition-all shadow-lg shadow-brand/20">
            {{ $step['cta_label'] }}
            <i class="fas fa-arrow-right ml-2 text-xs"></i>
        </a>
    </div>
</section>
@endif
