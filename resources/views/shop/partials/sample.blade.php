{{-- «Пример урока» — id="sample". Ведёт на публичный preview-роут (гейт-плеер
     Lesson с is_preview). Скрыт, если у курса нет опубликованного preview-урока. --}}
@if($course->previewLesson)
<section id="sample" class="mb-16 lg:mb-20" data-analytics="sample-lesson">
    <div class="flex items-center gap-4 mb-8">
        <h2 class="text-3xl font-bold text-white">Пример урока</h2>
        <span class="text-sm font-bold text-slate-500">бесплатно, без регистрации</span>
    </div>

    <a href="{{ route('shop.course.preview', $course->slug) }}"
       class="group block rounded-3xl overflow-hidden bg-[#111622] border border-[#1F2636] hover:border-[#E85C24]/50 transition-all duration-300">
        <div class="relative aspect-video w-full bg-gradient-to-br from-[#1A2235] to-[#0A0D14] flex items-center justify-center overflow-hidden">
            @if($course->image_path)
                <img src="{{ Storage::url($course->image_path) }}" alt=""
                     class="absolute inset-0 w-full h-full object-cover opacity-40 group-hover:opacity-50 transition-opacity">
            @endif
            <span class="relative flex items-center justify-center w-20 h-20 rounded-full bg-[#E85C24] text-white shadow-[0_0_40px_rgba(232,92,36,0.5)] group-hover:scale-110 transition-transform">
                <i class="fas fa-play text-2xl ml-1"></i>
            </span>
        </div>
        <div class="flex items-center justify-between gap-4 p-5">
            <div class="min-w-0">
                <div class="text-[11px] font-black uppercase tracking-widest text-[#E85C24] mb-1">Смотреть пробный урок</div>
                <div class="text-lg font-bold text-white truncate">{{ $course->previewLesson->title }}</div>
            </div>
            <i class="fas fa-arrow-right text-slate-500 group-hover:text-[#E85C24] group-hover:translate-x-1 transition-all shrink-0"></i>
        </div>
    </a>
</section>
@endif
