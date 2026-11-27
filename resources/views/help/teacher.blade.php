@extends('layouts.articles')

@section('title', 'Гид преподавателя — бот «Почтальон» в чате группы | ОРС')
@section('meta_description', 'Как управлять ботом @zapisi_ORSbot в учебном чате обычными фразами: остановить («стоп»), каникулы, отмена и перенос занятий. Кнопки, права, частые вопросы.')

@section('content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-10">

    <div class="bg-white rounded-3xl border border-brand/15 shadow-sm p-6 md:p-8 mb-8 text-center">
        <h1 class="text-2xl md:text-3xl font-extrabold text-gray-900 mb-3">Гид преподавателя: бот «Почтальон»</h1>
        <p class="text-gray-600 leading-relaxed">
            Управляйте ботом записи @zapisi_ORSbot обычными фразами прямо в чате
            своей группы: остановить серию, пауза на каникулы, отмена и перенос
            занятий. Панель не нужна — всё применяется кнопками бота.
        </p>
    </div>

    @if ($html === null)
        <div class="bg-white rounded-3xl border border-gray-100 shadow-sm p-6 md:p-8">
            <p class="text-gray-600">
                Руководство временно недоступно — напишите нам в
                <a href="https://t.me/rusamskrtam" class="text-brand underline font-semibold">Telegram</a>, поможем.
            </p>
        </div>
    @else
        <div class="bg-white rounded-3xl border border-gray-100 shadow-sm p-6 md:p-8">
            <div class="teacher-help prose max-w-none">
                {!! $html !!}
            </div>
        </div>
    @endif

</div>
@endsection

@push('styles')
<style>
    .teacher-help h1 { font-size: 1.5rem; font-weight: 800; margin: 2rem 0 .75rem; }
    .teacher-help h2 { font-size: 1.15rem; font-weight: 800; margin: 1.75rem 0 .5rem; }
    .teacher-help h2:first-child { margin-top: 0; }
    .teacher-help p, .teacher-help li { line-height: 1.7; color: #374151; }
    .teacher-help table { width: 100%; border-collapse: collapse; margin: .75rem 0; }
    .teacher-help th, .teacher-help td { border: 1px solid #e5e7eb; padding: .5rem .75rem; text-align: left; vertical-align: top; }
    .teacher-help th { background: #f9fafb; font-weight: 700; }
    .teacher-help blockquote { border-left: 4px solid #93c5fd; padding-left: 1rem; color: #374151; }
    .teacher-help code { background: #f3f4f6; border-radius: .25rem; padding: .1rem .35rem; }
</style>
@endpush
