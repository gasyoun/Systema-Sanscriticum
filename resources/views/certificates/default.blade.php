<!DOCTYPE html>
<html lang="ru">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <style>
        @page { margin: 0; size: a4 landscape; }
        
        body {
            margin: 0; padding: 0;
            font-family: 'DejaVu Serif', serif;
            color: #2c2c2c;
        }

        /* Контейнер, чтобы позиционирование было стабильным */
        .cert-container {
            position: relative;
            width: 100%; /* Ширина A4 landscape при 96dpi */
            height: 100%;
            overflow: hidden;
        }

        /* Фон */
        .bg-image {
            position: absolute;
            top: 0; left: 0;
            width: 100%; height: 100%;
            z-index: -100;
        }

        /* Имя студента */
        .student-name {
            position: absolute;
            top: 320px;
            left: 110px;
            width: 650px;
            font-size: 28px;
            font-weight: bold;
            color: #000;
        }

        /* Текст действия */
        .action-text {
            position: absolute;
            top: 385px;
            left: 110px;
            font-size: 16px;
            color: #555;
            font-style: italic;
        }

        /* Общий контейнер для блока курса */
        .course-container {
            position: absolute;
            top: 435px;  /* Точка старта названия */
            left: 110px;
            width: 600px;
            /* Высота авто — контейнер растянется сам */
        }

        /* Название курса (теперь внутри потока) */
        .course-title {
            position: relative; /* Или static */
            width: 100%;
            font-size: 26px;
            font-weight: bold;
            color: #000;
            line-height: 1.1;
            margin-bottom: 20px; /* <--- Отступ до часов. Меняй это число, чтобы двигать часы */
        }

        /* Часы (идут следом за названием) */
        .hours-text {
            position: relative;
            width: 100%;
            font-size: 16px;
            color: #333;
            /* Больше никаких top: 580px */
        }

        /* Баллы за экзамен (только шаблон «Санка») */
        /* Компактный блок в чистой полосе между строкой про объём и подписью Санки,
           слева (в одну колонку с остальным текстом), не доходя до фигуры Ганеши. */
        .exam-scores {
            position: absolute;
            top: 575px;
            left: 110px;
            width: 470px;
            font-size: 11px;
            color: #444;
        }
        .exam-scores .line {
            margin-bottom: 4px;
        }
        .exam-scores .crit {
            white-space: nowrap;
        }
        .exam-scores .sep {
            color: #bbb;
            margin: 0 6px;
        }
        .exam-scores .val {
            font-weight: bold;
            color: #000;
        }
        .exam-scores .total {
            font-size: 13px;
            color: #222;
        }

        /* QR-код */
        .qr-code {
            position: absolute;
            bottom: 120px;
            right: 85px;
            width: 50px;
            height: 50px;
        }

        /* Мета (Номер и дата) */
        .meta-info {
            position: absolute;
            bottom: 80px;
            right: 81px;
            width: 200px;
            text-align: right;
            font-size: 10px;
            color: #666;
            line-height: 1.3;
        }
    </style>
</head>
<body>

    <div class="cert-container">
        <img class="bg-image" src="{{ $bg_base64 }}">

        <div class="student-name">{{ $student_name }}</div>
        
        <div class="action-text">{{ $certificate->isSpravka() ? 'проходит обучение и освоил(а) часть онлайн-курса' : 'успешно освоил(а) онлайн-курс' }}</div>

        <div class="course-container">
        
        <div class="course-title">
            {!! str_replace('|', '<br>', e($course_title)) !!}
        </div>

        @unless($certificate->isSpravka())
        {{-- Для справки объём всего курса неверен: её окно занятий уже в названии. --}}
        <div class="hours-text">
            в объеме {{ $course->lessons_count ?? 12 }} уроков ({{ $course->hours_count ?? 24 }} академических часов)
        </div>
        @endunless

    </div>

        @if($certificate->showsExamScores())
            <div class="exam-scores">
                <div class="line">
                    @foreach(\App\Models\Certificate::EXAM_CRITERIA as $field => $crit)
                        <span class="crit">{{ $crit['label'] }} <span class="val">{{ \App\Models\Certificate::formatScore($certificate->$field) }} / {{ $crit['max'] }}</span></span>@if(! $loop->last)<span class="sep">·</span>@endif
                    @endforeach
                </div>
                <div class="line total">
                    Итого: <span class="val">{{ \App\Models\Certificate::formatScore($certificate->examTotal()) }} / {{ \App\Models\Certificate::examTotalMax() }}</span>
                </div>
            </div>
        @endif

        @if($qr_image)
            <img class="qr-code" src="{{ $qr_image }}">
        @endif

        <div class="meta-info">
            <b>№ {{ $number }}</b><br>
            Дата: {{ $date }}
        </div>
    </div>

</body>
</html>
