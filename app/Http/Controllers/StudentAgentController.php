<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Support\StudentAgent\StudentAgentService;
use App\Support\StudentAgentAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Личный кабинет: bounded student agent (H3231). Работает только при
 * features.student_agent=true (deploy-рубильник, ВЫКЛ по умолчанию) — как
 * AttendanceNoticeController, 404 при выключенном флаге, а не пустой ответ.
 *
 * H5193: при включённом флаге маршрут отвечает только платным членам клуба
 * (MG 20-09: ON for paying subscribers) — StudentAgentAccess::canUse(),
 * 403 при отсутствии платного уровня. Свободный доступ к LLM-оси — это
 * деньги, поэтому гейт стоит ДО валидации и вызова сервиса.
 */
class StudentAgentController extends Controller
{
    public function run(Request $request, StudentAgentService $agent): JsonResponse
    {
        if (! $agent->isEnabled()) {
            abort(404);
        }

        $user = $request->user();
        abort_unless($user !== null, 401);

        abort_unless(StudentAgentAccess::canUse($user), 403);

        $data = $request->validate([
            'tool' => ['required', 'string'],
            'params' => ['sometimes', 'array'],
            'confirm' => ['sometimes', 'boolean'],
        ]);

        $result = $agent->handle(
            $user,
            $data['tool'],
            $data['params'] ?? [],
            (bool) ($data['confirm'] ?? false),
        );

        return response()->json($result, $result['ok'] ? 200 : 422);
    }
}
