{{--
    H2168 — one reading pack of one cohort course.

    Reuses reading.partials.pack unchanged (the SAME reading_pack_v1 contract the
    public demo and the «Старт чтения» cabinet render), and passes NO $srsAdd, so the
    page is read-only: no «в колоду» button, no lookup telemetry, no POST target —
    exactly the fence H2168 was given.
--}}
@extends('layouts.student')

@section('title', $pack['title'])
@section('header', 'Чтение — '.$courseTitle)

@section('content')
    <div class="mb-6">
        <a href="{{ route('student.reading.course.index', $course) }}" class="text-sm text-gray-400 hover:text-brand transition-colors">← Все тексты курса</a>
    </div>

    @include('reading.partials.pack')
@endsection
