{{-- «Преподаватель(и)» — основной (teacher) + со-преподаватели (teachers),
     без дублей. Фото по photo_path, иначе инициал. Скрыт, если препода нет. --}}
@php
    $allTeachers = collect([$course->teacher])
        ->merge($course->teachers ?? [])
        ->filter()
        ->unique('id')
        ->values();
@endphp
@if($allTeachers->isNotEmpty())
<section id="teachers" class="mb-16 lg:mb-20" data-analytics="teachers">
    <h2 class="text-3xl font-bold text-white mb-8">{{ $allTeachers->count() > 1 ? 'Преподаватели' : 'Преподаватель' }}</h2>

    <div class="grid grid-cols-1 {{ $allTeachers->count() > 1 ? 'lg:grid-cols-2' : '' }} gap-6">
        @foreach($allTeachers as $teacher)
            <div class="flex flex-col sm:flex-row gap-6 rounded-2xl bg-[#111622] border border-[#1F2636] p-6">
                <div class="shrink-0">
                    @if($teacher->photo_path)
                        <img src="{{ Storage::url($teacher->photo_path) }}" alt="{{ $teacher->name }}"
                             class="w-24 h-24 rounded-2xl object-cover border border-[#1F2636]">
                    @else
                        <div class="w-24 h-24 rounded-2xl bg-gradient-to-br from-brand to-brand-hover flex items-center justify-center text-3xl font-extrabold text-white shadow-lg shadow-brand/20">
                            {{ mb_strtoupper(mb_substr($teacher->name, 0, 1)) }}
                        </div>
                    @endif
                </div>
                <div class="min-w-0">
                    <div class="text-[11px] font-black uppercase tracking-widest text-slate-500 mb-1">Преподаватель</div>
                    <h3 class="text-xl font-bold text-white mb-3">
                        <a href="{{ route('shop.index', ['teacher' => $teacher->id]) }}" class="hover:text-brand transition-colors">{{ $teacher->name }}</a>
                    </h3>
                    @php
                        $bioHtml = filled($teacher->bio)
                            ? $teacher->bio
                            : ($flagship['teacher_bio_html'] ?? null);
                    @endphp
                    @if(filled($bioHtml))
                        <div class="prose prose-invert prose-sm max-w-none text-slate-400 leading-relaxed [&_a]:text-indigo-400 [&_a:hover]:text-indigo-300 [&_a]:underline">
                            {!! \App\Support\SanitizedHtml::render($bioHtml) !!}
                        </div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</section>
@endif
