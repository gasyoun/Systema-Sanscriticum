{{-- Тематический силлабус флагмана (H5184 N04). Источник — config/flagship_landing.php
     (ключ syllabus программы); Filament-поля курса сильнее конфига в тех блоках,
     где они есть, у силлабуса своего поля в БД нет — рендер строго под
     flagship-overlay. Нумерация повторяет бейджи блоков «Программы курса». --}}
@php $syllabus = $flagship['syllabus'] ?? null; @endphp
@if(! empty($syllabus) && ! empty($syllabus['modules']))
<section id="syllabus" class="mb-16 lg:mb-20" data-analytics="flagship-syllabus">
    <h2 class="text-3xl font-bold text-white mb-8">{{ $syllabus['title'] }}</h2>

    <div class="space-y-3">
        @foreach($syllabus['modules'] as $i => $module)
            <div class="flex items-start gap-4 p-5 rounded-2xl bg-[#111622] border border-[#1F2636]">
                <span class="flex items-center justify-center shrink-0 w-10 h-10 rounded-xl bg-gradient-to-br from-[#1F2636] to-[#0A0D14] border border-[#1F2636] text-base font-extrabold text-white">
                    {{ $i + 1 }}
                </span>
                <p class="flex-1 min-w-0 pt-2 text-base font-bold text-white leading-relaxed">
                    {{ $module }}
                </p>
            </div>
        @endforeach
    </div>
</section>
@endif
