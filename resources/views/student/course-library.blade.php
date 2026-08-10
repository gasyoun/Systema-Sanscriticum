{{--
    Библиотека курса — фаза 1: реестр ссылок.
    Файлы лежат НЕ у нас (Dropbox, Google Drive, сайты издательств), поэтому
    страница честно говорит об этом: ссылка может перестать работать, и это
    нормально — куратор обновит строку в реестре.
--}}
@extends('layouts.student')

@section('title', 'Библиотека курса — ' . $course->title)
@section('header', 'Библиотека курса')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-12 font-nunito">

    <div class="mb-8 mt-6">
        <h2 class="text-3xl md:text-4xl font-extrabold text-[#101010] tracking-tight mb-2">
            Библиотека курса
        </h2>
        <p class="text-gray-500 text-lg max-w-3xl">
            Литература и материалы к курсу «{{ $course->title }}».
        </p>
        <p class="text-sm text-gray-400 mt-2 max-w-3xl">
            <i class="fas fa-external-link-alt text-[11px] mr-1"></i>
            Файлы хранятся не на нашем сервере — ссылки ведут на внешние ресурсы.
            Если ссылка перестала открываться, напишите куратору: он обновит её.
        </p>
    </div>

    @php
        $kinds = \App\Models\CourseMaterial::KINDS;
        $kindIcons = [
            'page' => 'fa-link',
            'pdf' => 'fa-file-pdf',
            'audio' => 'fa-headphones',
            'video' => 'fa-video',
            'archive' => 'fa-file-archive',
        ];
    @endphp

    @if($courseWide->isEmpty() && $byLesson->isEmpty())
        <div class="bg-white rounded-2xl border border-gray-200 p-10 text-center shadow-sm">
            <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-gray-100 text-gray-400 flex items-center justify-center">
                <i class="fas fa-book-open text-2xl"></i>
            </div>
            <h3 class="text-lg font-bold text-gray-900 mb-1">Библиотека пока пуста</h3>
            <p class="text-sm text-gray-500">Когда преподаватель добавит литературу к курсу — она появится здесь.</p>
        </div>
    @else
        @if($courseWide->isNotEmpty())
            <div class="mb-10">
                <h3 class="text-xs font-black uppercase tracking-widest text-gray-400 mb-4">
                    Общая литература курса
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
                    @foreach($courseWide as $material)
                        @include('student.partials.course-material-card', [
                            'material' => $material,
                            'kinds' => $kinds,
                            'kindIcons' => $kindIcons,
                        ])
                    @endforeach
                </div>
            </div>
        @endif

        @foreach($byLesson as $lessonId => $lessonMaterials)
            @php $lesson = $lessonMaterials->first()->lesson; @endphp
            <div class="mb-10">
                <h3 class="text-xs font-black uppercase tracking-widest text-gray-400 mb-4">
                    <a href="{{ route('student.lesson', ['slug' => $course->slug, 'lessonId' => $lessonId]) }}"
                       class="hover:text-brand transition-colors">
                        К уроку: {{ $lesson->title }}
                    </a>
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
                    @foreach($lessonMaterials as $material)
                        @include('student.partials.course-material-card', [
                            'material' => $material,
                            'kinds' => $kinds,
                            'kindIcons' => $kindIcons,
                        ])
                    @endforeach
                </div>
            </div>
        @endforeach
    @endif
</div>
@endsection
