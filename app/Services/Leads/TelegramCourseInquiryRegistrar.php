<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Models\Course;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\TelegramSupportMessage;
use Illuminate\Support\Str;

/**
 * Turns an explicit course-enrolment question in a private Telegram dialog
 * into one CRM lead. It never marks a payment or sends a message.
 */
class TelegramCourseInquiryRegistrar
{
    public function register(TelegramSupportMessage $message): ?Lead
    {
        if ($message->direction !== 'incoming' || $message->chat?->type !== 'private') {
            return null;
        }

        $text = trim((string) $message->text);
        if (! $this->isExplicitInquiry($text)) {
            return null;
        }

        $contact = $message->contact;
        $username = $contact?->username ? '@'.ltrim((string) $contact->username, '@') : null;
        $contactReference = $username ?: 'tg:'.($contact?->telegram_user_id ?: $message->telegram_chat_id);
        $course = $this->courseMention($text);
        [$source, $campaign] = $this->attribution($text);

        $lead = Lead::query()->where('telegram_chat_id', $message->telegram_chat_id)->first();

        if ($lead === null) {
            $lead = Lead::create([
                'name' => $contact?->name,
                'contact' => $contactReference,
                'social' => $username,
                'telegram_chat_id' => $message->telegram_chat_id,
                'status' => Lead::firstStageKey() ?? 'new',
                'utm_source' => $source,
                'utm_medium' => 'telegram_support',
                'utm_campaign' => $campaign,
                'utm_content' => $course?->slug,
            ]);
        } elseif ($lead->utm_source === null && $source !== null) {
            $lead->update([
                'utm_source' => $source,
                'utm_medium' => 'telegram_support',
                'utm_campaign' => $campaign,
                'utm_content' => $course?->slug,
            ]);
        }

        $subject = 'Интерес к курсу из Telegram';
        $body = 'Telegram support message #'.$message->id
            .($course ? "\nКурс: ".$course->title : "\nКурс: не распознан — уточнить")
            ."\n\n".$text;

        LeadNote::query()->firstOrCreate(
            ['lead_id' => $lead->id, 'subject' => $subject, 'body' => $body],
            ['type' => LeadNote::TYPE_NOTE, 'channel' => 'telegram'],
        );

        return $lead;
    }

    private function isExplicitInquiry(string $text): bool
    {
        return preg_match(
            '/(?:хочу|хотел(?:а)?\s+бы|можно|как)\s+(?:записа|учит|поступить|присоедин)|'
            .'(?:записа|набор|места?\s+в\s+групп|когда\s+(?:начн|старт)|интересует\s+(?:курс|обучен))/iu',
            $text,
        ) === 1;
    }

    private function courseMention(string $text): ?Course
    {
        $needle = Str::lower($text);

        return Course::query()
            ->whereNotNull('title')
            ->get()
            ->filter(fn (Course $course): bool => Str::length($course->title) >= 4
                && Str::contains($needle, Str::lower($course->title)))
            ->sortByDesc(fn (Course $course): int => Str::length($course->title))
            ->first();
    }

    /** @return array{0:?string,1:?string} */
    private function attribution(string $text): array
    {
        $upper = Str::upper($text);

        return match (true) {
            Str::contains($upper, 'ГРАММАТИКА-СВАМИ') => ['india_swami', 'grammar_gasuns_2026_09'],
            Str::contains($upper, 'ГРАММАТИКА-СЕГОДНЯ') => ['india_today', 'grammar_gasuns_2026_09'],
            Str::contains(Str::lower($text), 'грамматик') => ['ors', 'grammar_gasuns_2026_09'],
            default => ['telegram', 'course_inquiry'],
        };
    }
}
