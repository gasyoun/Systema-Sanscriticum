<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Сертификат</title>
    <style>
        /* Важно: DejaVu Sans поддерживает русский язык в PDF */
        body { 
            font-family: 'DejaVu Sans', sans-serif; 
            text-align: center; 
            margin: 0;
            padding: 0;
            color: #333;
        }
        .border-outer {
            border: 10px solid #4f46e5; /* Цвет индиго, как в нашем кабинете */
            padding: 10px;
            height: 94%;
        }
        .border-inner {
            border: 2px solid #4f46e5;
            height: 98%;
            padding: 40px;
            box-sizing: border-box;
        }
        .title {
            font-size: 45px;
            font-weight: bold;
            color: #111;
            margin-top: 50px;
            letter-spacing: 5px;
        }
        .subtitle {
            font-size: 18px;
            color: #666;
            margin-top: 40px;
        }
        .name {
            font-size: 35px;
            color: #4f46e5;
            text-transform: uppercase;
            margin-top: 20px;
            border-bottom: 1px solid #ccc;
            display: inline-block;
            padding-bottom: 5px;
        }
        .course {
            font-size: 24px;
            font-style: italic;
            font-weight: bold;
            margin-top: 30px;
        }
        .footer {
            margin-top: 100px;
            width: 100%;
        }
        .date {
            float: left;
            width: 200px;
            border-top: 1px solid #000;
            padding-top: 10px;
            text-align: center;
        }
        .signature {
            float: right;
            width: 200px;
            border-top: 1px solid #000;
            padding-top: 10px;
            text-align: center;
        }
        .cert-number {
            clear: both;
            margin-top: 60px;
            font-size: 12px;
            color: #999;
        }
    </style>
</head>
<body>
    <div class="border-outer">
        <div class="border-inner">
            <div class="title">СЕРТИФИКАТ</div>
            <div class="subtitle">Настоящий документ подтверждает, что</div>
            
            <div class="name">{{ $certificate->user->name }}</div>
            
            <div class="subtitle">успешно прошел(ла) обучение по программе курса</div>
            <div class="course">«{{ $certificate->course->title }}»</div>

            <div class="footer">
                <div class="date">
                    Дата: {{ \Carbon\Carbon::parse($certificate->issued_at)->format('d.m.Y') }}
                </div>
                <div class="signature">
                    Преподаватель
                </div>
            </div>
            
            <div class="cert-number">
                Регистрационный номер: {{ $certificate->number }}
            </div>
        </div>
    </div>
</body>
</html>
