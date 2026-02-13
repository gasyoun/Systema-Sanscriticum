@extends('layouts.student')
@section('header', 'Мои курсы')
@section('content')
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        @foreach($courses as $course)
            <div class="bg-white p-6 rounded-lg shadow">
                <h3 class="font-bold">{{ $course->title }}</h3>
                <a href="{{ route('student.course', $course->slug) }}" class="text-indigo-600">Открыть</a>
            </div>
        @endforeach
    </div>
@endsection
