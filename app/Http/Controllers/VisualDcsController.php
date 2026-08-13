<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ExternalLearningProgress;
use App\Services\Learning\ExternalLearningCatalog;
use App\Services\Learning\ExternalLearningProgressService;
use App\Support\VisualDcsEntitlement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * H2482 — native VisualDCS verb / nominal / concordance→passage surfaces.
 *
 * Each surface has its own OFF-by-default flag. A single flag off 404s that
 * surface only. Preview is public and bounded; attested-tier + writes need
 * an existing access-granting payment (or admin).
 */
class VisualDcsController extends Controller
{
    public function __construct(
        private readonly ExternalLearningCatalog $catalog,
        private readonly ExternalLearningProgressService $progress,
    ) {}

    public function preview(string $surface): View
    {
        $this->assertSurface($surface);

        return view('student.visualdcs.preview', [
            'surface' => $surface,
            'items' => $this->catalog->list($surface, previewOnly: true, includeAttested: false),
            'state' => VisualDcsEntitlement::PREVIEW,
            'release' => $this->catalog->promoted(),
        ]);
    }

    public function hub(Request $request): View
    {
        $user = $request->user();
        $surfaces = [];
        foreach ((array) config('visualdcs.surfaces', []) as $surface) {
            if (! $this->flagOn($surface)) {
                continue;
            }
            $surfaces[$surface] = [
                'enabled' => true,
                'items' => $this->visibleItems($surface, $user),
                'progress' => $user ? $this->progress->forUser($user, $surface) : [],
            ];
        }

        return view('student.visualdcs.hub', [
            'surfaces' => $surfaces,
            'state' => VisualDcsEntitlement::matrixState($user),
            'release' => $this->catalog->promoted(),
        ]);
    }

    public function index(Request $request, string $surface): View
    {
        $this->assertSurface($surface);
        $user = $request->user();

        return view('student.visualdcs.index', [
            'surface' => $surface,
            'items' => $this->visibleItems($surface, $user),
            'state' => VisualDcsEntitlement::matrixState($user),
            'progressById' => $this->progressMap($user, $surface),
            'release' => $this->catalog->promoted(),
        ]);
    }

    public function show(Request $request, string $surface, string $id): View
    {
        $this->assertSurface($surface);
        $user = $request->user();
        $item = $this->catalog->find($this->decodeId($id));
        abort_if($item === null || ($item['surface'] ?? null) !== $surface, 404);
        abort_if(! $this->maySee($item, $user), 404);

        $progress = null;
        if ($user && VisualDcsEntitlement::hasFullAccess($user)) {
            $this->progress->upsert(
                $user,
                (string) $item['id'],
                ExternalLearningProgress::STATUS_STARTED,
                null,
                ['source' => 'show'],
                $request,
            );
            $progress = collect($this->progress->forUser($user, $surface))
                ->first(fn ($row) => $row->object_id === $item['id']);
        }

        return view('student.visualdcs.show', [
            'surface' => $surface,
            'item' => $item,
            'state' => VisualDcsEntitlement::matrixState($user),
            'progress' => $progress,
            'canWrite' => $user && VisualDcsEntitlement::hasFullAccess($user),
        ]);
    }

    public function storeProgress(Request $request, string $surface, string $id): RedirectResponse
    {
        $this->assertSurface($surface);
        $user = $request->user();
        abort_unless($user && VisualDcsEntitlement::hasFullAccess($user), 404);

        $item = $this->catalog->find($this->decodeId($id));
        abort_if($item === null || ($item['surface'] ?? null) !== $surface, 404);
        abort_if(! $this->maySee($item, $user), 404);

        $data = $request->validate([
            'status' => 'required|in:started,completed,mastered',
            'score' => 'nullable|integer|min:0|max:100',
        ]);

        $this->progress->upsert(
            $user,
            (string) $item['id'],
            (string) $data['status'],
            isset($data['score']) ? (int) $data['score'] : null,
            ['source' => 'form'],
            $request,
        );

        return redirect()
            ->route('student.visualdcs.show', [$surface, $id])
            ->with('success', 'Прогресс сохранён.');
    }

    private function assertSurface(string $surface): void
    {
        abort_unless(in_array($surface, (array) config('visualdcs.surfaces', []), true), 404);
        abort_unless($this->flagOn($surface), 404);
    }

    private function flagOn(string $surface): bool
    {
        $flag = (string) (config('visualdcs.flag_by_surface.'.$surface) ?? '');

        return $flag !== '' && (bool) config('features.'.$flag, false);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function visibleItems(string $surface, $user): array
    {
        $full = VisualDcsEntitlement::hasFullAccess($user);

        return $this->catalog->list(
            $surface,
            previewOnly: ! $full,
            includeAttested: VisualDcsEntitlement::canSeeAttested($user),
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function maySee(array $item, $user): bool
    {
        $tier = (string) ($item['tier'] ?? 'attested');
        if ($tier === 'full') {
            return true;
        }

        return VisualDcsEntitlement::canSeeAttested($user);
    }

    /**
     * @return array<string, ExternalLearningProgress>
     */
    private function progressMap($user, string $surface): array
    {
        if (! $user) {
            return [];
        }
        $map = [];
        foreach ($this->progress->forUser($user, $surface) as $row) {
            $map[$row->object_id] = $row;
        }

        return $map;
    }

    private function decodeId(string $id): string
    {
        return rawurldecode($id);
    }
}
