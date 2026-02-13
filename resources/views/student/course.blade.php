@extends('layouts.student')

@section('title', $course->title)
@section('header', $course->title)

@section('content')
<div class="flex flex-col lg:flex-row gap-6">
    <div class="w-full lg:w-1/3">
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="p-4 border-b bg-gray-50">
                <h3 class="font-bold text-gray-700">Программа обучения</h3>
            </div>
            <ul class="divide-y divide-gray-200">
                @foreach($lessons as $index => $lesson)
                <li>
                    <a href="{{ route('student.lesson', [$course->slug, $lesson->id]) }}" 
                       class="block hover:bg-indigo-50 p-4 transition {{ isset($currentLesson) && $currentLesson->id == $lesson->id ? 'bg-indigo-100 border-l-4 border-indigo-600' : '' }}">
                        <div class="flex items-center">
                            <span class="flex-shrink-0 w-8 h-8 flex items-center justify-center border-2 border-indigo-500 rounded-full text-sm font-bold text-indigo-600 mr-3">
                                {{ $index + 1 }}
                            </span>
                            <div class="flex-1">
                                <p class="text-sm font-medium text-gray-900">{{ $lesson->title }}</p>
                                <p class="text-xs text-gray-500 italic">{{ $lesson->topic }}</p>
                            </div>
                        </div>
                    </a>
                </li>
                @endforeach
            </ul>
        </div>
    </div>

    <div class="w-full lg:w-2/3">
        @if(isset($currentLesson))
            <div class="bg-white shadow rounded-lg p-6">
                <div class="aspect-video bg-black rounded-lg mb-6 overflow-hidden">
                    @if($currentLesson->rutube_url)
                        <iframe width="100%" height="100%" src="{{ $currentLesson->rutube_url }}" frameBorder="0" allow="clipboard-write; autoplay" webkitAllowFullScreen mozallowfullscreen allowFullScreen></iframe>
                    @else
                        <div class="flex items-center justify-center h-full text-white italic">Видео временно недоступно</div>
                    @endif
                </div>
                <h2 class="text-2xl font-bold mb-2">{{ $currentLesson->title }}</h2>
                <div class="prose max-w-none text-gray-600">
                    {!! nl2br(e($currentLesson->topic)) !!}
                </div>
            </div>
        @else
            <div class="bg-indigo-50 border-2 border-dashed border-indigo-200 rounded-lg p-12 text-center">
                <i class="fas fa-play-circle text-4xl text-indigo-300 mb-4"></i>
                <h3 class="text-lg font-medium text-indigo-900">Выберите урок из списка слева</h3>
                <p class="text-indigo-600">Начните погружение в мир санскрита прямо сейчас!</p>
            </div>
        @endif
    </div>
</div>
@endsection
