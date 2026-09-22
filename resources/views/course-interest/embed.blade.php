{{-- H5066: минимальный iframe-вариант формы интереса для встраивания на
     samskrtam.ru (<iframe src="https://samskrte.ru/interest/{slug}/embed">).
     Стоит вне blade-лэйаутов: собственный <html> без навигации и хрома. --}}
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $courseTitle !== '' ? 'Интерес к курсу — '.$courseTitle : 'Интерес к курсу' }}</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; padding: 16px; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; color: #1A1A1A; background: #ffffff; }
        h1 { font-size: 20px; font-weight: 800; margin: 0 0 6px; }
        p.lead { font-size: 13px; color: #6b7280; margin: 0 0 14px; line-height: 1.5; }
        form { display: flex; flex-direction: column; gap: 12px; }
        fieldset { border: 0; padding: 0; margin: 0; }
        legend { font-size: 13px; font-weight: 700; margin-bottom: 6px; }
        label.opt { display: flex; align-items: center; gap: 8px; border: 1px solid #e5e7eb; border-radius: 10px; padding: 8px 12px; font-size: 13px; cursor: pointer; }
        label.opt:hover { border-color: #d1d5db; }
        input[type="text"], input[type="email"], textarea { width: 100%; border: 1px solid #e5e7eb; border-radius: 10px; padding: 9px 12px; font-size: 13px; font-family: inherit; }
        input:focus, textarea:focus { outline: 2px solid rgba(99,102,241,.35); border-color: #a5b4fc; }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        @media (max-width: 480px) { .row { grid-template-columns: 1fr; } }
        button { align-self: flex-start; border: 0; border-radius: 10px; background: #6366f1; color: #fff; font-weight: 700; font-size: 13px; padding: 10px 18px; cursor: pointer; }
        button:hover { opacity: .9; }
        .flash-ok { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; border-radius: 10px; padding: 10px 12px; font-size: 13px; font-weight: 600; }
        .flash-err { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: 10px; padding: 10px 12px; font-size: 13px; }
        .hp { position: absolute; left: -9999px; top: -9999px; width: 1px; height: 1px; overflow: hidden; }
    </style>
</head>
<body>
    <h1>{{ $courseTitle !== '' ? '«'.$courseTitle.'»' : 'Интерес к курсу' }}</h1>
    <p class="lead">Оставьте заявку — куратор напишет вам, когда соберется группа или откроется набор.</p>

    @if (session('course_interest_status'))
        <div class="flash-ok">{{ session('course_interest_status') }}</div>
    @endif
    @if ($errors->any())
        <div class="flash-err">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('course-interest.store', ['course' => $courseSlug]) }}">
        @csrf
        <div class="hp" aria-hidden="true">
            <label>Оставьте это поле пустым
                <input type="text" name="website" tabindex="-1" autocomplete="off" value="">
            </label>
        </div>
        <input type="hidden" name="ff_ts" value="{{ encrypt((string) now()->timestamp) }}">

        <fieldset>
            <legend>Что вы хотите?</legend>
            @php
                // H5233: префилл из query — как в полной форме.
                $ciIntent = old('intent', $intentPrefill ?? \App\Models\CourseInterestRequest::INTENT_JOIN);
            @endphp
            @foreach ($intentLabels as $intentValue => $intentLabel)
                <label class="opt">
                    <input type="radio" name="intent" value="{{ $intentValue }}" required
                           @checked($ciIntent === $intentValue)>
                    <span>{{ $intentLabel }}</span>
                </label>
            @endforeach
        </fieldset>

        <input type="text" name="name" maxlength="255" placeholder="Имя (необязательно)" value="{{ old('name') }}">

        <div class="row">
            <input type="email" name="email" maxlength="255" placeholder="Email или" value="{{ old('email') }}">
            <input type="text" name="telegram" maxlength="255" placeholder="Telegram @username" value="{{ old('telegram') }}">
        </div>

        <textarea name="comment" maxlength="1000" rows="2" placeholder="Комментарий (необязательно)">{{ old('comment') }}</textarea>

        <button type="submit">Оставить заявку</button>
    </form>
</body>
</html>
