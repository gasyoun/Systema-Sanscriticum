<?php

declare(strict_types=1);

namespace App\Services\Zoom;

use App\Models\User;
use App\Models\WebinarAttendance;
use Illuminate\Support\Carbon;

/**
 * Запись посещаемости вебинара из данных Zoom — единая точка для вебхука
 * (participant_joined/left) и pull-сверки (Reports API). Одна строка на сессию
 * участия (schedule_id + participant_uuid), идемпотентно; студент матчится по
 * email, иначе остаётся гостем (user_id = NULL).
 */
class AttendanceRecorder
{
    /** Событие вебхука: participant_joined ставит joined_at, participant_left — left_at+duration. */
    public function recordWebhookEvent(int $scheduleId, array $p, string $event): void
    {
        $values = [
            'name' => $p['user_name'] ?? null,
            'email' => ! empty($p['email']) ? (string) $p['email'] : null,
        ];

        if ($event === 'meeting.participant_left') {
            $values['left_at'] = $this->parseTime($p['leave_time'] ?? null);
            $values['duration_seconds'] = isset($p['duration']) ? (int) $p['duration'] : null;
        } else {
            $values['joined_at'] = $this->parseTime($p['join_time'] ?? null);
        }

        $this->record($scheduleId, $this->participantKey($p), $values);
    }

    /** Строка из Reports API (встреча уже прошла): есть и join, и leave, и duration. */
    public function recordReportRow(int $scheduleId, array $p): void
    {
        $this->record($scheduleId, $this->participantKey($p), [
            'name' => $p['name'] ?? ($p['user_name'] ?? null),
            'email' => ! empty($p['user_email']) ? (string) $p['user_email'] : (! empty($p['email']) ? (string) $p['email'] : null),
            'joined_at' => $this->parseTime($p['join_time'] ?? null),
            'left_at' => $this->parseTime($p['leave_time'] ?? null),
            'duration_seconds' => isset($p['duration']) ? (int) $p['duration'] : null,
        ]);
    }

    /**
     * H3761 — вставка строки Reports API БЕЗ обновления уже существующей.
     * Отличие от recordReportRow(): при коллизии ключа (schedule_id +
     * participant_uuid) строка не трогается вовсе. Нужно бэкфилу волны 3,
     * которому запрещены UPDATE/DELETE в боевых данных: повторный прогон
     * обязан быть no-op, а не «переписать то, что уже собрано вебхуком».
     *
     * @return bool true — строка вставлена; false — уже была или ключ пуст
     */
    public function insertReportRowIfMissing(int $scheduleId, array $p): bool
    {
        $uuid = $this->participantKey($p);
        if (trim($uuid, '|') === '') {
            return false;
        }

        if (WebinarAttendance::where('schedule_id', $scheduleId)
            ->where('zoom_participant_uuid', $uuid)
            ->exists()
        ) {
            return false;
        }

        $email = ! empty($p['user_email']) ? (string) $p['user_email'] : (! empty($p['email']) ? (string) $p['email'] : null);

        WebinarAttendance::create(array_filter([
            'schedule_id' => $scheduleId,
            'zoom_participant_uuid' => $uuid,
            'name' => $p['name'] ?? ($p['user_name'] ?? null),
            'email' => $email,
            'user_id' => $email ? User::where('email', User::normalizeEmail($email))->value('id') : null,
            'joined_at' => $this->parseTime($p['join_time'] ?? null),
            'left_at' => $this->parseTime($p['leave_time'] ?? null),
            'duration_seconds' => isset($p['duration']) ? (int) $p['duration'] : null,
        ], fn ($v) => $v !== null));

        return true;
    }

    private function record(int $scheduleId, string $uuid, array $values): void
    {
        if (trim($uuid, '|') === '') {
            return;
        }

        $email = $values['email'] ?? null;
        $values['user_id'] = $email
            ? User::where('email', User::normalizeEmail($email))->value('id')
            : null;

        WebinarAttendance::updateOrCreate(
            ['schedule_id' => $scheduleId, 'zoom_participant_uuid' => $uuid],
            // null-значения не затирают уже записанное (join не сбрасывает left, и наоборот).
            array_filter($values, fn ($v) => $v !== null),
        );
    }

    /**
     * Тот же ключ идемпотентности, что использует запись, — но снаружи.
     * Нужен планировщику бэкфила (H3761), который считает «сколько строк
     * добавится» ДО того, как что-то писать.
     */
    public function participantKeyFor(array $p): string
    {
        return $this->participantKey($p);
    }

    /** participant_uuid стабилен на подключение; фолбэк — user_id|join_time. */
    private function participantKey(array $p): string
    {
        $uuid = (string) ($p['participant_uuid'] ?? '');
        if ($uuid === '') {
            $uuid = (string) ($p['user_id'] ?? '').'|'.(string) ($p['join_time'] ?? '');
        }

        return $uuid;
    }

    private function parseTime(?string $time): ?string
    {
        return $time ? Carbon::parse($time)->toDateTimeString() : null;
    }
}
