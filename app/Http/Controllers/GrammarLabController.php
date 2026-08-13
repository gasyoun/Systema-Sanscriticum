<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\GrammarBookmark;
use App\Models\GrammarExercise;
use App\Models\GrammarMastery;
use App\Models\GrammarTopic;
use App\Models\GrammarTopicView;
use App\Services\GrammarLab\GrammarLabPilot;
use App\Services\GrammarLab\GrammarLabSearch;
use App\Services\GrammarLab\GrammarMasteryProjector;
use App\Services\GrammarLab\GrammarRecommender;
use App\Support\GrammarLabAccess;
use App\Support\GrammarLabSrsDeck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * H2493 — entitled Grammar Lab explorer.
 *
 * Flag OFF → 404 (prod-inert). Flag ON without canUse() → 403 and no
 * topic/vector/exercise payload. Search JSON is the same gate.
 */
class GrammarLabController extends Controller
{
    public function __construct(
        private readonly GrammarLabSearch $search,
        private readonly GrammarRecommender $recommender,
        private readonly GrammarMasteryProjector $mastery,
        private readonly GrammarLabPilot $pilot,
    ) {}

    public function landing(): View|Response
    {
        $this->assertFeature();

        $count = GrammarTopic::query()->where('status', 'published')->count();

        return view('grammar-lab.landing', [
            'topicCount' => $count,
            'pin' => config('grammar_lab.pin'),
        ]);
    }

    public function index(Request $request): View|Response
    {
        $this->assertEntitled($request);

        $cluster = trim((string) $request->query('cluster', ''));
        $q = GrammarTopic::query()->where('status', 'published')->orderBy('title_ru');
        if ($cluster !== '') {
            $q->where('cluster', $cluster);
        }
        $topics = $q->with('sources')->get();
        $clusters = GrammarTopic::query()
            ->where('status', 'published')
            ->whereNotNull('cluster')
            ->distinct()
            ->orderBy('cluster')
            ->pluck('cluster');

        return view('grammar-lab.index', [
            'topics' => $topics,
            'clusters' => $clusters,
            'cluster' => $cluster,
            'query' => (string) $request->query('q', ''),
            'bookmarks' => $this->bookmarkIds($request),
            'recommendation' => $this->recommender->next($request->user()),
        ]);
    }

    public function search(Request $request): View|JsonResponse|Response
    {
        $this->assertEntitled($request);

        $q = trim((string) $request->query('q', ''));
        $hits = $q === '' ? [] : $this->search->search($q, (int) config('grammar_lab.search.top_k', 10));

        if ($request->wantsJson() || $request->query('format') === 'json') {
            return response()->json([
                'q' => $q,
                'semantic' => $this->search->semanticAvailable(),
                'results' => array_map(fn (array $hit) => [
                    'topic_id' => $hit['topic_id'],
                    'slug' => $hit['slug'],
                    'title_ru' => $hit['title_ru'],
                    'snippet' => $hit['snippet'],
                    'sources' => $hit['sources'],
                    'score' => $hit['score'],
                ], $hits),
            ]);
        }

        return view('grammar-lab.search', [
            'q' => $q,
            'hits' => $hits,
            'semantic' => $this->search->semanticAvailable(),
        ]);
    }

    public function show(Request $request, string $slug): View|Response
    {
        $this->assertEntitled($request);
        $topic = $this->publishedTopic($slug);
        $this->recordView($request, $topic);

        return view('grammar-lab.show', [
            'topic' => $topic,
            'bookmarked' => $this->isBookmarked($request, $topic->topic_id),
            'exercise' => $this->publishedExercise($topic),
            'mastery' => GrammarMastery::query()
                ->where('user_id', $request->user()->id)
                ->where('topic_id', $topic->topic_id)
                ->first(),
            'pilotParticipant' => $this->pilot->participantFor($request->user()),
            'pilotOn' => $this->pilot->flagOn(),
            'confusionCodes' => GrammarLabPilot::CONFUSION_CODES,
        ]);
    }

    public function compare(Request $request, string $slug): View|Response
    {
        $this->assertEntitled($request);
        $topic = $this->publishedTopic($slug);
        $this->recordView($request, $topic);
        $this->pilot->record($request->user(), GrammarLabPilot::EVENT_TASK, [
            'topic_id' => $topic->topic_id,
            'surface' => 'compare',
        ]);

        return view('grammar-lab.compare', [
            'topic' => $topic,
            'whitney' => $topic->sources->where('family', 'whitney')->values(),
            'zalizniak' => $topic->sources->where('family', 'zalizniak')->values(),
            'bookmarked' => $this->isBookmarked($request, $topic->topic_id),
        ]);
    }

    public function bookmark(Request $request, string $slug): RedirectResponse|Response
    {
        $this->assertEntitled($request);
        $topic = $this->publishedTopic($slug);
        $user = $request->user();
        $existing = GrammarBookmark::query()
            ->where('user_id', $user->id)
            ->where('topic_id', $topic->topic_id)
            ->first();
        if ($existing) {
            $existing->delete();
        } else {
            GrammarBookmark::query()->create([
                'user_id' => $user->id,
                'topic_id' => $topic->topic_id,
            ]);
        }

        return back();
    }

    public function practice(Request $request, string $slug): View|Response
    {
        $this->assertEntitled($request);
        $topic = $this->publishedTopic($slug);
        $exercise = $this->publishedExercise($topic);
        abort_if($exercise === null, 404);

        return view('grammar-lab.practice', [
            'topic' => $topic,
            'exercise' => $exercise,
            'payload' => is_array($exercise->payload) ? $exercise->payload : [],
            'mastery' => $this->mastery->for($request->user(), $topic->topic_id),
            'result' => null,
            'recommendation' => null,
        ]);
    }

    public function attempt(Request $request, string $slug): View|RedirectResponse|Response
    {
        $this->assertEntitled($request);
        $topic = $this->publishedTopic($slug);
        $exercise = $this->publishedExercise($topic);
        abort_if($exercise === null, 404);

        $given = trim((string) $request->input('answer', ''));
        if ($given === '') {
            return back()->withErrors(['answer' => 'Введите ответ']);
        }

        $attempt = $this->mastery->record($request->user(), $exercise, $given);
        $this->pilot->record($request->user(), GrammarLabPilot::EVENT_QUIZ, [
            'topic_id' => $topic->topic_id,
            'exercise_id' => $exercise->exercise_id,
            'correct' => (bool) $attempt->correct,
        ]);

        return view('grammar-lab.practice', [
            'topic' => $topic,
            'exercise' => $exercise,
            'payload' => is_array($exercise->payload) ? $exercise->payload : [],
            'mastery' => $this->mastery->for($request->user(), $topic->topic_id),
            'result' => [
                'correct' => $attempt->correct,
                'given' => $attempt->given,
            ],
            'recommendation' => $this->recommender->next($request->user()),
        ]);
    }

    public function addToSrs(Request $request, string $slug): RedirectResponse|Response
    {
        $this->assertEntitled($request);
        $topic = $this->publishedTopic($slug);
        $exercise = $this->publishedExercise($topic);
        abort_if($exercise === null, 404);

        GrammarLabSrsDeck::addExercise($request->user(), $exercise);

        return back()->with('status', 'added_to_srs');
    }

    public function history(Request $request): View|Response
    {
        $this->assertEntitled($request);
        $views = GrammarTopicView::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('viewed_at')
            ->limit(40)
            ->get();
        $topics = GrammarTopic::query()
            ->whereIn('topic_id', $views->pluck('topic_id'))
            ->get()
            ->keyBy('topic_id');
        $bookmarks = GrammarTopic::query()
            ->whereIn('topic_id', $this->bookmarkIds($request))
            ->orderBy('title_ru')
            ->get();

        return view('grammar-lab.history', [
            'views' => $views,
            'topics' => $topics,
            'bookmarks' => $bookmarks,
        ]);
    }

    private function publishedTopic(string $slug): GrammarTopic
    {
        $topic = GrammarTopic::query()
            ->where('slug', $slug)
            ->where('status', 'published')
            ->with('sources')
            ->first();
        abort_if($topic === null, 404);

        return $topic;
    }

    private function assertFeature(): void
    {
        abort_unless(GrammarLabAccess::featureOn(), 404);
    }

    private function assertEntitled(Request $request): void
    {
        $this->assertFeature();
        $user = $request->user();
        if (GrammarLabAccess::canUse($user)) {
            return;
        }

        abort(403, 'Нет доступа к Лаборатории грамматики');
    }

    private function recordView(Request $request, GrammarTopic $topic): void
    {
        $user = $request->user();
        if ($user === null) {
            return;
        }
        GrammarTopicView::query()->create([
            'user_id' => $user->id,
            'topic_id' => $topic->topic_id,
            'viewed_at' => now(),
        ]);
    }

    /**
     * @return list<string>
     */
    private function bookmarkIds(Request $request): array
    {
        $user = $request->user();
        if ($user === null) {
            return [];
        }

        return GrammarBookmark::query()
            ->where('user_id', $user->id)
            ->pluck('topic_id')
            ->all();
    }

    private function isBookmarked(Request $request, string $topicId): bool
    {
        return in_array($topicId, $this->bookmarkIds($request), true);
    }

    private function publishedExercise(GrammarTopic $topic): ?GrammarExercise
    {
        $ids = is_array($topic->exercise_ids) ? $topic->exercise_ids : [];
        $query = GrammarExercise::query()
            ->where('topic_id', $topic->topic_id)
            ->where('status', GrammarExercise::STATUS_PUBLISHED)
            ->whereNull('killed_at');
        if ($ids !== []) {
            $query->whereIn('exercise_id', $ids);
        }

        return $query->orderBy('exercise_id')->first();
    }
}
