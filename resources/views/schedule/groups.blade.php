{{-- H5233: /raspisanie/kochergina — живые группы семейства Кочергиной --}}
{{-- с канва-курсором «Урок N из total» и кликом на заявку. Визуальный язык --}}
{{-- 1:1 за schedule/page.blade.php (H4340/H4647): те же лэйаут, палитра и классы. --}}
@extends('layouts.shop')

@section('title', 'Группы грамматики — канва и набор')

@section('content')
<div class="max-w-4xl mx-auto px-4 py-12">
    <h1 class="text-3xl font-bold text-white mb-3">Группы грамматики по Кочергиной</h1>
    <p class="text-slate-400 mb-10">
        Каждая живая группа — с текущим уроком учебника («Урок N из 40», канва)
        и преподавателем. Клик по группе открывает заявку: в новую группу или
        перевод из вашей текущей группы — форма подставит сама.
        Общее расписание — <a href="/raspisanie" class="sch-index-teacher">на странице «Расписание»</a>.
    </p>

    @if(!$flagOn)
        <p class="text-slate-500">Список групп скоро появится на этой странице.</p>
    @elseif($rows->isEmpty())
        <p class="text-slate-500">Сейчас нет идущих групп с предстоящими занятиями.</p>
    @else
        <style>
            .kg-card { background: #111622; border: 1px solid #1F2636; border-radius: 1rem; padding: 1.25rem 1.5rem; }
            .kg-name { color: #fff; font-weight: 700; font-size: 1.15rem; }
            a.kg-name:hover { color: #E85C24; }
            .kg-canvas { color: #cbd5e1; font-size: .925rem; margin: .35rem 0 0; }
            .kg-canvas strong { color: #E85C24; font-weight: 700; }
            .kg-teacher { color: #94a3b8; font-size: .875rem; text-decoration: underline; text-underline-offset: 3px; }
            .kg-teacher:hover { color: #E85C24; }
            .kg-cta { display: inline-flex; align-items: center; gap: 8px; padding: .625rem 1rem; border-radius: .75rem;
                background: #E85C24; color: #fff; font-size: .75rem; font-weight: 700; transition: all .2s ease; }
            .kg-cta:hover { background: #d14e1b; }
        </style>

        <div class="space-y-6" id="kochergina-groups">
            @foreach($rows as $row)
                @php $course = $row['course']; @endphp
                <div class="kg-card" data-kg-group="{{ $row['groupName'] }}">
                    <div class="fs-top">
                        <span class="sch-main">
                            <a href="{{ $row['interestUrl'] }}" class="kg-name">{{ $row['groupName'] }}</a>
                        </span>
                        <span class="sch-meta">
                            @foreach($row['teachers'] as $t)
                                <a href="{{ $t['url'] }}" class="kg-teacher">{{ $t['name'] }}</a>@if(!$loop->last), @endif
                            @endforeach
                        </span>
                    </div>
                    <p class="kg-canvas">
                        Канва: <strong>{{ $row['canvasCursor'] > 0 ? 'Урок '.$row['canvasCursor'].' из '.$row['canvasTotal'] : 'Урок —' }}</strong>
                        @if($row['canvasTotal'] > 0 && $row['canvasCursor'] > 0)
                            · {{ $row['canvasTotal'] - $row['canvasCursor'] }} урок(ов) до конца учебника
                        @endif
                    </p>
                    <div class="sch-cta">
                        <a href="{{ $row['interestUrl'] }}"
                           class="kg-cta">
                            @if($row['joinIntent'] === 'transfer')
                                Перевестись в эту группу
                            @else
                                Встать в заявку
                            @endif
                            <i class="fas fa-arrow-right text-[10px]"></i>
                        </a>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection