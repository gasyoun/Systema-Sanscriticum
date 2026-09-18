<?php

declare(strict_types=1);

namespace App\Services\Survey;

use App\Models\SurveyEvent;
use App\Models\SurveyInvitation;
use App\Models\SurveyResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Писатель событий воронки анкет (H5098). Идемпотентность:
 *  - sent      — одна строка на приглашение (статус sent достигается один раз);
 *  - opened    — одна строка на (slug, session);
 *  - started   — одна строка на (slug, session);
 *  - page      — одна строка на (slug, session, страница) — ретраи фронта гасятся;
 *  - completed — одна строка на ответ (per-response, повторная отправка = новая строка).
 *
 * События без session_key (заблокированные куки) не пишутся для opened/started/page —
 * их не с чем сопоставлять в воронке, а дубликаты на каждый запрос делали бы
 * агрегат шумом. completed пишется всегда — он привязан к ответу.
 */
class SurveyFunnelRecorder
{
    public function invitationSent(SurveyInvitation $invitation): void
    {
        $this->write(
            slug: (string) $invitation->survey_slug,
            event: SurveyEvent::SENT,
            link: ['survey_invitation_id' => $invitation->id, 'user_id' => $invitation->user_id],
            dedupe: ['survey_invitation_id' => $invitation->id],
        );
    }

    public function opened(string $slug, ?string $sessionKey, ?int $userId = null): void
    {
        if ($sessionKey === null) {
            return;
        }

        $this->write($slug, SurveyEvent::OPENED, ['user_id' => $userId], ['session_key' => $sessionKey]);
    }

    public function started(string $slug, string $sessionKey, ?int $userId = null): void
    {
        $this->write($slug, SurveyEvent::STARTED, ['user_id' => $userId], ['session_key' => $sessionKey]);
    }

    public function page(string $slug, string $sessionKey, int $pageIndex): void
    {
        $this->write($slug, SurveyEvent::PAGE, [], [
            'session_key' => $sessionKey,
            'page_index' => max(1, min(30, $pageIndex)),
        ]);
    }

    public function completed(string $slug, SurveyResponse $response, ?string $sessionKey = null): void
    {
        $invitationId = $response->user_id === null
            ? null
            : SurveyInvitation::query()
                ->where('survey_slug', $slug)
                ->where('user_id', $response->user_id)
                ->value('id');

        $this->write(
            slug: $slug,
            event: SurveyEvent::COMPLETED,
            link: [
                'survey_invitation_id' => $invitationId,
                'user_id' => $response->user_id,
                'session_key' => $sessionKey,
            ],
            dedupe: ['survey_response_id' => $response->id],
        );
    }

    /**
     * @param  array<string, mixed>  $link
     * @param  array<string, mixed>  $dedupe  дополнительные столбцы firstOrCreate
     * @param  array<string, mixed>  $extra
     */
    private function write(string $slug, string $event, array $link = [], array $dedupe = [], array $extra = []): void
    {
        try {
            SurveyEvent::firstOrCreate(
                array_merge([
                    'survey_slug' => $slug,
                    'event' => $event,
                ], $dedupe),
                array_merge($link, $extra),
            );
        } catch (QueryException $e) {
            // Телеметрия не должна ломать основной поток (страницу/отправку/рассылку).
            Log::info('survey funnel event write failed', ['slug' => $slug, 'event' => $event]);
        }
    }
}
