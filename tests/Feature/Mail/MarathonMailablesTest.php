<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Mail\MarathonDay1Mail;
use App\Mail\MarathonDay2Mail;
use App\Mail\MarathonDay3Mail;
use App\Mail\MarathonRecordingMail;
use App\Mail\MarathonWelcomeMail;
use Illuminate\Mail\Mailable;
use Tests\TestCase;

/**
 * H1148: пять Mailable марафона — рендер, рулевые темы дословно, очередь
 * 'mailing', ни одного неразрешенного {placeholder} после рендера с полным
 * набором данных. Канал инертен (ESP-гейт H1147) — тесты офлайн.
 */
class MarathonMailablesTest extends TestCase
{
    /** @return array<string, array{0: Mailable, 1: string}> */
    public static function mailables(): array
    {
        return [
            'welcome' => [new MarathonWelcomeMail(tgLink: 'https://t.me/samskrte_bot?start=x', host: 'М. Ю. Гасунс'),
                'Вы записаны. Как устроены ваши три дня'],
            'day1' => [new MarathonDay1Mail(link: 'https://samskrte.ru/marathon/day/1?t=x'),
                'День 1: санскрит роднее, чем кажется'],
            'day2' => [new MarathonDay2Mail(link: 'https://samskrte.ru/marathon/day/2?t=x', host: 'М. Ю. Гасунс'),
                'День 2: как устроено санскритское слово'],
            'day3-paid' => [new MarathonDay3Mail(paid: true, date: '30-08-2026 19:00 МСК', host: 'М. Ю. Гасунс', link: 'https://zoom.us/j/1'),
                'Завтра живая консультация — ваш вопрос уже у ведущего'],
            'day3-free' => [new MarathonDay3Mail(paid: false, date: '30-08-2026 19:00 МСК', host: 'М. Ю. Гасунс', link: 'https://zoom.us/j/1'),
                'Живая консультация 30-08-2026 19:00 МСК — запись пришлем сюда'],
            'recording' => [new MarathonRecordingMail(recordingLink: 'https://samskrte.ru/rec/1', coupon: '500', host: 'М. Ю. Гасунс'),
                'Запись консультации и ваша скидка 500 ₽'],
        ];
    }

    public function test_all_five_render_with_ruled_subjects_on_mailing_queue(): void
    {
        foreach (self::mailables() as $name => [$mailable, $subject]) {
            $html = $mailable->render();

            $this->assertNotSame('', trim($html), "{$name}: empty render");
            $this->assertSame($subject, $mailable->envelope()->subject, "{$name}: subject drifted from the ruled copy");
            $this->assertSame('mailing', $mailable->queue, "{$name}: not on the mailing queue");
            $this->assertDoesNotMatchRegularExpression(
                '/\{[a-z_]+\}/',
                $html,
                "{$name}: unresolved {placeholder} survived rendering",
            );
        }
    }

    public function test_no_emoji_or_urgency_in_rendered_bodies(): void
    {
        $forbidden = ['🎉', '🚀', '✨', '🙏', '🎁', '!!!', 'только сегодня', 'успей', 'осталось'];
        foreach (self::mailables() as $name => [$mailable, $_]) {
            $html = $mailable->render();
            foreach ($forbidden as $token) {
                $this->assertStringNotContainsString($token, $html, "{$name}: urgency/emoji token «{$token}»");
            }
        }
    }
}
