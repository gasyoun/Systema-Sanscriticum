<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $announcement->title }}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f5f7; font-family: Arial, sans-serif;">
    <table width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color: #f4f5f7; padding: 40px 0;">
        <tr>
            <td align="center">
                <table width="600" border="0" cellspacing="0" cellpadding="0" style="background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">
                    
                    <tr>
                        <td style="background-color: #19191C; padding: 30px; text-align: center;">
                            <h2 style="color: #ffffff; margin: 0; font-size: 24px; letter-spacing: 1px;">Платформа Обучения</h2>
                        </td>
                    </tr>

                    @if($announcement->image_path)
                    <tr>
                        <td>
                            <img src="{{ asset('storage/' . $announcement->image_path) }}" width="600" style="display: block; max-width: 100%; height: auto;">
                        </td>
                    </tr>
                    @endif

                    <tr>
                        <td style="padding: 40px;">
                            <h1 style="color: #1A1A1A; font-size: 24px; margin-top: 0; margin-bottom: 20px;">{{ $announcement->title }}</h1>
                            
                            <p style="color: #555555; font-size: 16px; line-height: 1.6;">Здравствуйте, {{ $user->name }}!</p>

                            <div style="color: #333333; font-size: 16px; line-height: 1.6; margin-bottom: 30px;">
                                {!! $announcement->content !!}
                            </div>

                            @if($announcement->button_text && $announcement->button_url)
                            <div style="text-align: center; margin-top: 40px;">
                                <a href="{{ $announcement->button_url }}" style="background-color: #E85C24; color: #ffffff; text-decoration: none; padding: 14px 32px; border-radius: 8px; font-weight: bold; font-size: 16px; display: inline-block;">
                                    {{ $announcement->button_text }}
                                </a>
                            </div>
                            @endif
                        </td>
                    </tr>

                    <tr>
                        <td style="background-color: #f9fafb; padding: 20px; text-align: center; border-top: 1px solid #eeeeee;">
                            <p style="color: #999999; font-size: 13px; margin: 0;">
                                Вы получили это письмо, так как являетесь студентом нашей платформы.<br>
                                Чтобы прочитать сообщение в кабинете, <a href="{{ route('student.messages') }}" style="color: #E85C24;">нажмите здесь</a>.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>