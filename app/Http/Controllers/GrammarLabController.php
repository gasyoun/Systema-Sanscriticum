<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\GrammarBookmark;
use App\Models\GrammarTopic;
use App\Models\GrammarTopicView;
use App\Services\GrammarLab\GrammarLabSearch;
use App\Support\GrammarLabAccess;
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
        ]);
    }

    public function compare(Request $request, string $slug): View|Response
    {
        $this->assertEntitled($request);
        $topic = $this->publishedTopic($slug);
        $this->recordView($request, $topic);

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
}
