<?php

declare(strict_types=1);

namespace App\Services\OpenLessonCards;

use App\Models\Course;
use App\Models\OpenLessonCardSend;
use App\Models\Schedule;
use App\Models\Teacher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Карточка открытого занятия (по образцу ботов конференций): фото, подпись
 * «🎙 название / 📅 когда / кто ведёт / ссылку пришлём», inline «✅ Я иду».
 * Нижнее меню — отдельная reply-клавиатура: Telegram даёт сообщению либо
 * inline-кнопки, либо меню, поэтому меню едет отдельным сообщением.
 */
class OpenLessonCardBuilder
{
    /** Лимит подписи к фото в Telegram. */
    public const CAPTION_LIMIT = 1024;

    public const STAGE_MANUAL = 'manual';

    public const CALLBACK_GO = 'ol:go:';

    public const CALLBACK_NO = 'ol:no:';

    public const CALLBACK_CARD = 'ol:card:';

    public function __construct(private readonly OpenLessonOccasions $occasions) {}

    public function caption(Schedule $schedule, string $stage, ?Carbon $now = null): string
    {
        $now ??= now();
        $start = $schedule->start->copy()->timezone(config('app.timezone'))->locale('ru');
        $title = e($this->title($schedule));

        $relative = match (true) {
            $start->isSameDay($now) => 'Сегодня',
            $start->isSameDay($now->copy()->addDay()) => 'Завтра',
            default => null,
        };

        $heading = $stage === OpenLessonCardSend::STAGE_MORNING && $relative !== null
            ? "🎙 {$relative} открытое занятие «{$title}»"
            : '🎙 '.$this->ucfirst($start->isoFormat('dddd, D MMMM'))." — открытое занятие «{$title}»";

        $when = ($relative !== null ? $relative.', ' : '').$start->isoFormat('D MMMM').', в '.$start->format('H:i');

        $lines = [
            $heading,
            '',
            "📅 {$when} (МСК), онлайн в Zoom. Участие бесплатное.",
        ];

        $hosts = $this->hostsLine($this->occasions->courseFor($schedule));
        $note = trim((string) $schedule->card_note);
        $tail = 'Ссылку на подключение пришлём в этот чат перед началом.';

        $body = array_filter([$hosts, $note !== '' ? e($note) : null]);
        $caption = implode("\n", $lines)."\n\n".($body !== [] ? implode(' ', $body)."\n\n" : '').$tail;

        // Подпись длиннее лимита Telegram не примет — жертвуем припиской.
        if (mb_strlen(strip_tags($caption)) > self::CAPTION_LIMIT && $note !== '') {
            $caption = implode("\n", $lines)."\n\n".($hosts !== null ? $hosts."\n\n" : '').$tail;
        }

        return $caption;
    }

    /**
     * @param  'going'|'declined'|null  $answer
     * @return array<int, array<int, array<string, string>>>
     */
    public function inlineKeyboard(Schedule $schedule, ?string $answer = null): array
    {
        $id = (int) $schedule->getKey();

        $rows = [$answer === 'going'
            ? [
                ['text' => '✅ Вы записаны', 'callback_data' => self::CALLBACK_GO.$id],
                ['text' => 'Не смогу', 'callback_data' => self::CALLBACK_NO.$id],
            ]
            : [['text' => '✅ Я иду', 'callback_data' => self::CALLBACK_GO.$id]],
        ];

        $course = $this->occasions->courseFor($schedule);
        if ($course !== null && filled($course->slug)) {
            $rows[] = [['text' => '📖 О курсе', 'url' => $this->courseUrl($course)]];
        }

        return $rows;
    }

    /** Нижнее меню бота (постоянная reply-клавиатура): первая кнопка во всю ширину, дальше по две. */
    public function replyMenu(): array
    {
        $buttons = array_map(fn (string $label): array => ['text' => $label], array_values((array) config('open_lesson_cards.menu', [])));
        $first = array_shift($buttons);

        return [
            'keyboard' => array_merge($first !== null ? [[$first]] : [], array_chunk($buttons, 2)),
            'resize_keyboard' => true,
            'is_persistent' => true,
            'input_field_placeholder' => 'Выберите кнопку или напишите вопрос',
        ];
    }

    /** Текст сообщения со ссылкой на подключение (стадия zoom). */
    public function zoomText(Schedule $schedule): string
    {
        $start = $schedule->start->copy()->timezone(config('app.timezone'));

        return '🔔 Скоро начинаем: «'.e($this->title($schedule)).'», в '.$start->format('H:i')." (МСК).\n\n"
            .'Подключайтесь по кнопке ниже — ждём вас!';
    }

    /**
     * Картинка карточки как JPEG на диске: свой баннер занятия → баннер
     * курса 16:9 → 4:3 → обложка витрины. Telegram не берёт webp фотографией,
     * поэтому всё, что не jpg/png, перекодируется GD и кэшируется.
     *
     * @return array{file: string, key: string}|null key — для кэша file_id Telegram
     */
    public function photoFor(Schedule $schedule): ?array
    {
        foreach ($this->photoCandidates($schedule) as [$disk, $path]) {
            try {
                $storage = Storage::disk($disk);
                if (! $storage->exists($path)) {
                    continue;
                }
                $absolute = $storage->path($path);
            } catch (\Throwable) {
                continue;
            }

            $key = 'olc:photo:'.sha1($absolute.'|'.(string) @filemtime($absolute));
            $extension = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));
            if (in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
                return ['file' => $absolute, 'key' => $key];
            }

            $jpeg = $this->toJpeg($absolute, $key);
            if ($jpeg !== null) {
                return ['file' => $jpeg, 'key' => $key];
            }
        }

        return null;
    }

    public function title(Schedule $schedule): string
    {
        $title = trim((string) $schedule->title);

        return $title !== '' ? $title : (string) ($this->occasions->courseFor($schedule)?->title ?? 'Открытое занятие');
    }

    public function courseUrl(Course $course): string
    {
        return rtrim((string) config('app.url'), '/').route('shop.course.show', $course->slug, false);
    }

    /** @return list<array{0: string, 1: string}> */
    private function photoCandidates(Schedule $schedule): array
    {
        $candidates = [];
        if (filled($schedule->card_image_path)) {
            $candidates[] = ['public', (string) $schedule->card_image_path];
        }

        $course = $this->occasions->courseFor($schedule);
        if ($course !== null) {
            foreach (['16:9', '4:3'] as $format) {
                $asset = $course->designAssets()->where('format', $format)->first();
                if ($asset !== null && filled($asset->path)) {
                    $candidates[] = [(string) ($asset->disk ?: 'public'), (string) $asset->path];
                }
            }
            if (filled($course->image_path)) {
                $candidates[] = [(string) config('filesystems.default'), (string) $course->image_path];
            }
        }

        return $candidates;
    }

    private function toJpeg(string $source, string $key): ?string
    {
        $target = storage_path('app/open-lesson-cards/'.substr(sha1($key), 0, 20).'.jpg');
        if (is_file($target)) {
            return $target;
        }

        $image = @imagecreatefromstring((string) @file_get_contents($source));
        if ($image === false) {
            Log::warning('OpenLessonCardBuilder: cannot decode card image', ['source' => $source]);

            return null;
        }

        // Прозрачность webp/png на белом фоне, а не на чёрном.
        $canvas = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        if (! is_dir(dirname($target))) {
            @mkdir(dirname($target), 0775, true);
        }
        $ok = imagejpeg($canvas, $target, 90);
        imagedestroy($image);
        imagedestroy($canvas);

        return $ok ? $target : null;
    }

    private function hostsLine(?Course $course): ?string
    {
        if ($course === null) {
            return null;
        }

        $names = $course->allTeachers()
            ->sortBy(fn (Teacher $t): int => $t->id === $course->teacher_id ? 0 : 1)
            ->map(function (Teacher $teacher): string {
                $name = '<b>'.e((string) $teacher->name).'</b>';
                $url = $teacher->publicPageUrl();

                return $url !== null ? '<a href="'.e($url).'">'.$name.'</a>' : $name;
            })
            ->values()
            ->all();

        if ($names === []) {
            return null;
        }

        $last = array_pop($names);
        $list = $names === [] ? $last : implode(', ', $names).' и '.$last;

        return (count($names) === 0 ? 'Ведёт ' : 'Ведут ').$list.'.';
    }

    private function ucfirst(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }
}
