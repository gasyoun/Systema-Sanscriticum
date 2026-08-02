@extends('layouts.student')

@section('title', $pack['title'])
@section('header', 'Чтение с разбором')

@section('content')
    <div class="mb-6">
        <a href="{{ route('student.reading.index') }}" class="text-sm text-gray-400 hover:text-[#E85C24] transition-colors">← Все тексты курса</a>
    </div>

    @include('reading.partials.pack')
@endsection
