@extends('layouts.shop')

@section('title', 'Реестр выданных сертификатов')

@push('head')
    <link rel="canonical" href="{{ route('certificate.registry') }}">
    <meta name="description" content="Публичный реестр сертификатов и справок Общества ревнителей санскрита: кому, за какой курс и когда выдан документ. Проверка подлинности по номеру.">
@endpush

@section('content')
<div class="container mx-auto px-4 py-12 md:py-16">
    <div class="max-w-5xl mx-auto">

        <h1 class="text-2xl md:text-3xl font-extrabold text-white mb-2">Реестр выданных сертификатов</h1>
        <p class="text-slate-400 mb-8">
            Документы Общества ревнителей санскрита. Нажмите на номер, чтобы проверить подлинность.
        </p>

        {{-- Табы-сортировки + фильтры --}}
        <div class="flex flex-wrap items-center gap-3 mb-6">
            @foreach(['date' => 'По дате', 'name' => 'По алфавиту', 'group' => 'По группам'] as $key => $label)
                <a href="{{ route('certificate.registry', array_filter(['sort' => $key, 'course' => $filters['course'], 'year' => $filters['year']])) }}"
                   class="px-4 py-2 rounded-full text-sm font-bold transition-colors {{ $sort === $key ? 'bg-brand text-white' : 'bg-[#111622] border border-[#1F2636] text-slate-300 hover:border-brand' }}">
                    {{ $label }}
                </a>
            @endforeach

            <form method="GET" action="{{ route('certificate.registry') }}" class="flex flex-wrap items-center gap-2 ml-auto">
                <input type="hidden" name="sort" value="{{ $sort }}">
                <select name="course" class="bg-[#111622] border border-[#1F2636] text-slate-300 text-sm rounded-xl px-3 py-2">
                    <option value="">Все курсы</option>
                    @foreach($courses as $course)
                        <option value="{{ $course->slug }}" @selected($filters['course'] === $course->slug)>{{ $course->title }}</option>
                    @endforeach
                </select>
                <select name="year" class="bg-[#111622] border border-[#1F2636] text-slate-300 text-sm rounded-xl px-3 py-2">
                    <option value="">Все годы</option>
                    @foreach($years as $year)
                        <option value="{{ $year }}" @selected((int) ($filters['year'] ?? 0) === $year)>{{ $year }}</option>
                    @endforeach
                </select>
                <button type="submit" class="px-4 py-2 rounded-xl bg-[#111622] border border-[#1F2636] text-sm font-bold text-slate-300 hover:border-brand transition-colors">
                    Показать
                </button>
            </form>
        </div>

        @if($certificates->isEmpty())
            <div class="bg-[#111622] border border-[#1F2636] rounded-3xl p-10 text-center text-slate-400">
                По выбранным условиям документов не найдено.
            </div>
        @else
            <div class="bg-[#111622] border border-[#1F2636] rounded-3xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-[11px] uppercase tracking-widest text-slate-500 border-b border-[#1F2636]">
                                <th class="px-5 py-4 font-bold">Выдан</th>
                                <th class="px-5 py-4 font-bold">Курс</th>
                                <th class="px-5 py-4 font-bold">Тип</th>
                                <th class="px-5 py-4 font-bold">Номер</th>
                                <th class="px-5 py-4 font-bold">Дата</th>
                                <th class="px-5 py-4 font-bold">Группа</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($certificates as $cert)
                                <tr class="border-b border-[#1F2636]/60 hover:bg-white/[0.02] transition-colors">
                                    <td class="px-5 py-3 font-semibold text-white whitespace-nowrap">{{ $cert->displayStudentName() }}</td>
                                    <td class="px-5 py-3 text-slate-300">{{ str_replace('|', ' ', $cert->displayCourseTitle()) }}</td>
                                    <td class="px-5 py-3">
                                        <span class="px-2 py-0.5 rounded-full text-[11px] font-bold {{ $cert->isSpravka() ? 'bg-sky-500/10 text-sky-300' : 'bg-emerald-500/10 text-emerald-300' }}">
                                            {{ $cert->isSpravka() ? 'Справка' : 'Сертификат' }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3 whitespace-nowrap">
                                        <a href="{{ route('certificate.verify', $cert->number) }}" class="font-mono font-semibold text-brand hover:underline">
                                            {{ $cert->number }}
                                        </a>
                                    </td>
                                    <td class="px-5 py-3 text-slate-400 whitespace-nowrap">{{ \Carbon\Carbon::parse($cert->issued_at)->format('d.m.Y') }}</td>
                                    <td class="px-5 py-3 text-slate-400">{{ $cert->group->name ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-6">
                {{ $certificates->links() }}
            </div>
        @endif

    </div>
</div>
@endsection
