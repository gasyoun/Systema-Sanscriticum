<!doctype html>
<html lang="ru"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Преподавательский глоссарий · samskrte.ru</title>
<style>
body{margin:0;background:#f4efe4;color:#20221f;font:17px/1.6 Georgia,serif}
main{max-width:860px;margin:6vh auto;padding:24px}
.seal{font:700 12px system-ui;letter-spacing:.14em;text-transform:uppercase;color:#8b3b2f}
h1{font-size:40px;line-height:1.1;margin:.2em 0}
form{margin:20px 0;display:flex;gap:8px;flex-wrap:wrap}
input[type=search]{flex:1;min-width:220px;padding:10px 14px;font:inherit;border:1px solid #bcaf99;background:#fffdf7;border-radius:6px}
button{padding:10px 18px;font:inherit;font-weight:bold;background:#8b3b2f;color:#f4efe4;border:0;border-radius:6px;cursor:pointer}
table{width:100%;border-collapse:collapse;background:#fffdf7;border:1px solid #bcaf99;border-radius:6px}
th,td{padding:8px 10px;border-bottom:1px solid #e4dbc8;text-align:left;vertical-align:top}
th{font:700 12px system-ui;letter-spacing:.06em;text-transform:uppercase;color:#6b5d49;background:#efe7d4}
td.num{text-align:right;white-space:nowrap;color:#6b5d49}
.slp1{font-family:ui-monospace,Menlo,monospace;font-size:14px;color:#4a5a3f}
.amb{color:#8b3b2f;font:700 11px system-ui}
.note{color:#6b5d49;font-size:14px}
details{margin:18px 0}
summary{cursor:pointer;font-weight:bold;color:#8b3b2f}
.back{display:inline-block;margin-bottom:14px;color:#8b3b2f;font-weight:bold}
@media (max-width:640px){td:nth-child(4),th:nth-child(4),td:nth-child(5),th:nth-child(5){display:none}}
</style></head>
<body><main>
<div class="seal">Тир Top · только для членов</div>
<h1>Преподавательский глоссарий</h1>
<p class="note">{{ $stats['total'] }} терминов из живой преподавательской речи (частота ≥ 20), профиль по {{ $stats['courses'] }} курсам. Поиск — по русской форме, глоссе или SLP1-лемме.</p>

<form method="get" action="{{ route('cabinet.teaching-glossary') }}">
    <input type="search" name="q" value="{{ $query }}" placeholder="вишну, Атман, vizRu…" aria-label="Поиск по глоссарию">
    <button type="submit">Найти</button>
    @if($query !== '')<a class="back" href="{{ route('cabinet.teaching-glossary') }}">сброс</a>@endif
</form>

<table>
<thead><tr><th>Форма</th><th>Словарная единица (SLP1)</th><th>Перевод / глосса</th><th>Частота</th><th>Курсы</th></tr></thead>
<tbody>
@forelse($terms as $term)
<tr>
    <td><strong>{{ $term->cyrillic_form }}</strong>@if($term->ambiguous_lemmas) <span class="amb">неск. лемм</span>@endif</td>
    <td class="slp1">{{ $term->lemma_slp1 }}</td>
    <td>{{ $term->ru_gloss }}</td>
    <td class="num">{{ number_format($term->corpus_freq, 0, ',', ' ') }}</td>
    <td class="num">{{ $term->n_courses }}</td>
</tr>
@empty
<tr><td colspan="5">Ничего не найдено@if($query !== '') — «{{ $query }}»@endif.</td></tr>
@endforelse
</tbody>
</table>

@if($courses->isNotEmpty())
<details>
    <summary>Профиль по курсам ({{ $courses->count() }})</summary>
    <table>
    <thead><tr><th>Курс</th><th>Терминов</th><th>Самые частые</th></tr></thead>
    <tbody>
    @foreach($courses as $course)
    <tr><td>{{ $course->course }}</td><td class="num">{{ $course->n_headwords }}</td><td class="note">{{ $course->top_terms }}</td></tr>
    @endforeach
    </tbody>
    </table>
</details>
@endif

<p class="note">Доступ входит в подписку тира Top. Материал агрегирован по курсам, без данных об участниках.</p>
</main></body></html>
