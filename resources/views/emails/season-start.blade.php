<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f5f7; font-family: Arial, sans-serif;">
    <table width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color: #f4f5f7; padding: 40px 0;">
        <tr>
            <td align="center">
                <table width="600" border="0" cellspacing="0" cellpadding="0" style="background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">

                    <tr>
                        <td style="background-color: #E85C24; padding: 28px 30px; text-align: center;">
                            <h2 style="color: #ffffff; margin: 0; font-size: 21px; line-height: 1.3; font-weight: bold; letter-spacing: 0.3px;">
                                Общество ревнителей санскрита
                            </h2>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 40px; color: #1A1A1A; font-size: 16px; line-height: 1.6;">
                            <p style="margin: 0 0 16px;">Намасте, {{ $firstName }}!</p>

                            <p style="margin: 0 0 16px;">
                                <strong>{{ $startDate }}</strong> стартует <strong>{{ $title }}</strong> —
                                игровой сезон бесплатных санскритских тренажёров. Он продлится до {{ $endDate }}.
                            </p>

                            <p style="margin: 0 0 12px;">Что это значит для вас:</p>
                            <ul style="margin: 0 0 16px; padding-left: 22px;">
                                <li style="margin-bottom: 8px;">
                                    За решённые раунды начисляется <strong>прана</strong> — она поднимает вас по рангам:
                                    Śiṣya → Adhyāyin → Snātaka → Ācārya → Paṇḍita.
                                </li>
                                <li style="margin-bottom: 8px;">
                                    Лидерборд сезона считается от <strong>базового снапшота</strong> на момент старта:
                                    у всех равный отсчёт с первого дня. Подарки праны между студентами на позицию
                                    не влияют — важен только ваш собственный заработок за сезон.
                                </li>
                                <li style="margin-bottom: 8px;">
                                    Лучшие игроки сезона получают призы:
                                    @foreach ($rewards as $reward)
                                        {{ $reward['position'] }} место — {{ number_format($reward['amount'], 0, ',', ' ') }} праны{{ $loop->last ? '.' : ',' }}
                                    @endforeach
                                </li>
                            </ul>

                            <p style="margin: 0; text-align: center;">
                                <a href="{{ $playUrl }}" style="display: inline-block; background-color: #E85C24; color: #ffffff; text-decoration: none; font-weight: bold; padding: 14px 28px; border-radius: 8px;">
                                    Играть в /lila/
                                </a>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 0 40px 32px; color: #9aa0a6; font-size: 12px; line-height: 1.5;">
                            Вы получили это письмо, потому что играли в тренажёрах или были активны в кабинете
                            Общества ревнителей санскрита.
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
