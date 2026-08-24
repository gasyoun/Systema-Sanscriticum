<!DOCTYPE html>
<html lang="ru">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <style>
        @page { margin: 0; size: a4 landscape; }

        body {
            margin: 0; padding: 0;
            font-family: 'DejaVu Serif', serif;
            color: #1f2430;
        }

        .cert-container {
            position: relative;
            width: 100%;
            height: 100%;
            box-sizing: border-box;
            background: #fdfbf6;
        }

        /* Двойная рамка «дипломного» типа */
        .frame-outer {
            position: absolute;
            top: 28px; left: 28px; right: 28px; bottom: 28px;
            border: 3px solid #2c3a63;
        }
        .frame-inner {
            position: absolute;
            top: 40px; left: 40px; right: 40px; bottom: 40px;
            border: 1px solid #b08d4a;
        }

        .corner-accent {
            position: absolute;
            top: 52px; right: 52px;
            font-size: 44px;
            color: #b08d4a;
        }

        .org-name {
            position: absolute;
            top: 78px; left: 0; right: 0;
            text-align: center;
            font-size: 13px;
            letter-spacing: 4px;
            text-transform: uppercase;
            color: #7a7466;
        }

        .cert-title {
            position: absolute;
            top: 128px; left: 0; right: 0;
            text-align: center;
            font-size: 44px;
            font-weight: bold;
            color: #2c3a63;
        }

        .cert-subtitle {
            position: absolute;
            top: 196px; left: 0; right: 0;
            text-align: center;
            font-size: 15px;
            color: #7a7466;
            font-style: italic;
        }

        .gift-label {
            position: absolute;
            top: 268px; left: 120px;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: #b08d4a;
            margin-bottom: 10px;
        }

        /* Что подарено: название тарифа + курса */
        .gift-content {
            position: absolute;
            top: 292px; left: 120px;
            width: 560px;
        }
        .gift-tariff {
            font-size: 26px;
            font-weight: bold;
            color: #1f2430;
        }
        .gift-course {
            margin-top: 10px;
            font-size: 18px;
            color: #55504a;
        }

        .gift-note {
            position: absolute;
            top: 420px; left: 120px;
            width: 520px;
            font-size: 14px;
            line-height: 1.6;
            color: #7a7466;
            font-style: italic;
        }

        .verify-block {
            position: absolute;
            bottom: 70px; left: 120px;
            width: 430px;
        }
        .verify-caption {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: #7a7466;
        }
        .verify-url {
            font-size: 12px;
            color: #2c3a63;
            word-break: break-all;
        }

        .footer-meta {
            position: absolute;
            bottom: 70px; right: 120px;
            text-align: right;
        }
        .footer-date {
            font-size: 12px;
            color: #7a7466;
        }
        .footer-number {
            font-size: 13px;
            font-family: 'DejaVu Sans Mono', monospace;
            font-weight: bold;
            color: #2c3a63;
            margin-top: 4px;
        }

        .qr-image {
            position: absolute;
            bottom: 64px; right: 340px;
            width: 92px;
            height: 92px;
        }
    </style>
</head>
<body>
<div class="cert-container">
    <div class="frame-outer"></div>
    <div class="frame-inner"></div>

    <div class="corner-accent">ॐ</div>

    <div class="org-name">Общество ревнителей санскрита · samskrte.ru</div>

    <div class="cert-title">Подарочный сертификат</div>
    <div class="cert-subtitle">Право обучения по выбранному направлению Общества</div>

    <div class="gift-label">В подарок</div>
    <div class="gift-content">
        <div class="gift-tariff">{{ $certificate->tariff_title }}</div>
        @if($certificate->course)
            <div class="gift-course">{{ $certificate->course->title }}</div>
        @endif
    </div>

    <div class="gift-note">
        Сертификат активируется одноразовым кодом на странице
        samskrte.ru/gift/activate — доступ к обучению откроется сразу после активации.
        Код передаётся получателю отдельно (в письме дарителя).
    </div>

    @if($qr_image)
        <img class="qr-image" src="{{ $qr_image }}" alt="QR">
    @endif

    <div class="verify-block">
        <div class="verify-caption">Подлинность сертификата</div>
        <div class="verify-url">{{ $verifyUrl }}</div>
    </div>

    <div class="footer-meta">
        <div class="footer-date">{{ $date }}</div>
        <div class="footer-number">{{ $certificate->number }}</div>
    </div>
</div>
</body>
</html>
