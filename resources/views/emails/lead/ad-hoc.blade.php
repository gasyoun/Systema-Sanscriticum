<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $subjectLine }}</title>
</head>
<body style="font-family: 'Georgia', serif; background-color: #fcf9f2; color: #3e3a37; line-height: 1.6; margin: 0; padding: 40px 20px;">

    <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 40px 30px; border-top: 6px solid #E85C24; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">

        @if ($leadName)
            <h2 style="color: #c0531f; font-size: 22px; margin-top: 0; font-weight: normal;">Намасте, {{ $leadName }}!</h2>
        @endif

        {{-- Тело письма — готовый HTML из RichEditor (вводит менеджер в админке). --}}
        <div style="font-size: 17px;">
            {!! \App\Support\SanitizedHtml::render($bodyText) !!}
        </div>

        <hr style="border: none; border-top: 1px solid #f0e6d2; margin: 36px 0;">

        <p style="margin-top: 0; font-size: 14px; color: #95a5a6; text-align: center;">
            Общество ревнителей санскрита
        </p>
    </div>

</body>
</html>
