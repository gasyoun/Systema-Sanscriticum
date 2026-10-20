@extends('layouts.student')

@section('title', 'Прописи — доска')

@section('content')
<div class="sa-main p-4 sm:p-6 space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-[#19191C]">Прописи — деванагари</h1>
            <p class="text-sm text-gray-600 mt-1">
                @if(isset($lesson) && $lesson)
                    Доска занятия «{{ $lesson->title }}».
                @else
                    Ваша личная доска. Выберите букву из Library (внизу панели) — обводите её кистью.
                @endif
                Доска сохраняется сама: перезагрузка, другой браузер, другой день — всё на месте.
            </p>
        </div>
        <span id="board-save-status" class="text-xs text-gray-500">&nbsp;</span>
    </div>

    {{-- Лениво грузимый чанк Excalidraw + React: основной кабинет его не тянет --}}
    @vite('resources/js/devanagari-board.js')

    <div id="devanagari-board-root"
         class="w-full bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden"
         style="height: calc(100dvh - 220px); min-height: 480px;"
         data-scene-url="{{ route('student.propisi.scene.show', request()->query('lesson') ?: []) }}"
         data-save-url="{{ route('student.propisi.scene.save', request()->query('lesson') ?: []) }}"
         data-library-url="{{ asset('libraries/devanagari-stencils.excalidrawlib') }}"
    ></div>

    <p class="text-xs text-gray-500">
        Трафареты букв — в панели Library (значок в правом нижнем углу). Загрузить другие трафареты:
        Library → «…» → Import из файла <code>.excalidrawlib</code>.
    </p>
</div>
@endsection
