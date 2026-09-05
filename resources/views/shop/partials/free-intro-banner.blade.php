{{--
  Site-wide free-intro / trial CTA (H2365 / H2760).

  Consumes App\Support\NextIntroSession via $nextIntro (View composer on layouts.shop).
  Empty source → omit the date line. Never invent a date and never show FALLBACK_LABEL.
--}}
@php
    use App\Support\NextIntroSession;

    $intro = $nextIntro ?? NextIntroSession::resolve();
    $hasDate = $intro !== null && ! empty($intro['date_label']);
    $dateLabel = $hasDate ? $intro['date_label'] : null;
    $ctaUrl = $intro['cta_url'] ?? route('shop.index');
    $ctaLabel = $intro['cta_label'] ?? 'Бесплатное вводное занятие';
    $titleLine = $intro['course_title'] ?? null;
    $isFree = $intro['is_free'] ?? true;
@endphp
<div data-analytics="free-intro-banner"
     data-intro-source="{{ $intro['source'] ?? 'empty' }}"
     data-intro-has-date="{{ $hasDate ? '1' : '0' }}"
     class="border-b border-brand/25 bg-gradient-to-r from-[#1A2235] via-[#151b2a] to-[#1A2235]">
    <div class="container mx-auto px-4 py-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div class="flex items-start sm:items-center gap-3 min-w-0">
            <span class="shrink-0 w-9 h-9 rounded-xl bg-brand/15 border border-brand/35 text-brand flex items-center justify-center"
                  aria-hidden="true">
                <i class="fas fa-calendar-check text-sm"></i>
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-black uppercase tracking-widest text-brand/90 mb-0.5">
                    {{ $isFree ? 'Бесплатное вводное' : 'Пробное занятие' }}
                </p>
                @if($hasDate)
                    <p class="text-sm sm:text-base font-semibold text-white leading-snug">
                        Ближайшее — <span class="text-[#38BDF8]">{{ $dateLabel }}</span>
                        @if($titleLine)
                            <span class="text-slate-400 font-medium"> · {{ $titleLine }}</span>
                        @endif
                    </p>
                @endif
            </div>
        </div>
        <a href="{{ $ctaUrl }}"
           data-analytics="free-intro-banner-cta"
           class="shrink-0 inline-flex items-center justify-center gap-2 px-4 py-2.5 min-h-[44px] rounded-xl bg-brand hover:bg-brand-hover text-white text-sm font-bold transition-all hover:-translate-y-0.5 shadow-[0_0_20px_rgba(232,92,36,0.25)]">
            {{ $hasDate ? 'Записаться' : 'Смотреть курсы' }}
            <i class="fas fa-arrow-right text-xs opacity-90"></i>
        </a>
    </div>
</div>
