@extends('layouts.articles')

@section('title', 'Статьи о санскрите — Общество ревнителей санскрита')
@section('meta_description', 'Статьи о санскрите: грамматика, философия, практика, культура. Честный разбор без мистики и пафоса.')

@section('content')

{{-- Компактный заголовок страницы --}}
<div class="article-hero" style="padding: 60px 24px 40px;">
    <div class="article-hero__deva">ग्रन्थ</div>
    <div class="article-hero__inner">
        <h1 class="article-hero__title">Статьи</h1>
        <p class="article-hero__lead">
            Разборы, практика и философия санскрита — без пафоса и воды.
        </p>
    </div>
</div>

<div class="articles-layout">

    {{-- ═══════════════ САЙДБАР: РУБРИКИ + ПОИСК ═══════════════ --}}
    <aside class="articles-sidebar">
        <form method="GET" action="{{ route('articles.index') }}" class="mb-6">
            <h4>Поиск</h4>
            <input type="search"
                   name="q"
                   value="{{ request('q') }}"
                   placeholder="Название статьи..."
                   class="search-input"
                   autocomplete="off">
            {{-- Скрытое поле, чтобы поиск не сбрасывал выбранную рубрику --}}
            @if(request('category'))
                <input type="hidden" name="category" value="{{ request('category') }}">
            @endif
        </form>

        <h4>Рубрики</h4>
        <ul class="category-list">
            <li>
                <a href="{{ route('articles.index', array_filter(['q' => request('q')])) }}"
                   class="{{ !request('category') ? 'active' : '' }}">
                    <span>Все статьи</span>
                    <span class="count">{{ $totalCount }}</span>
                </a>
            </li>
            @foreach($categories as $cat)
                <li>
                    <a href="{{ route('articles.index', array_filter(['category' => $cat->slug, 'q' => request('q')])) }}"
                       class="{{ request('category') === $cat->slug ? 'active' : '' }}">
                        <span>{{ $cat->name }}</span>
                        <span class="count">{{ $cat->published_articles_count }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </aside>

    {{-- ═══════════════ СЕТКА СТАТЕЙ ═══════════════ --}}
    <div>
        @if($articles->isEmpty())
            <div class="articles-empty">
                <i class="far fa-folder-open"></i>
                <p>
                    @if(request('q') || request('category'))
                        По вашему запросу ничего не найдено.
                        <br>
                        <a href="{{ route('articles.index') }}" style="color: var(--art-accent); font-weight: 600;">Сбросить фильтры</a>
                    @else
                        Пока нет опубликованных статей.
                    @endif
                </p>
            </div>
        @else
            {{-- Подсказка о текущих фильтрах --}}
            @if(request('q') || request('category'))
                <div style="margin-bottom: 20px; font-size: .9rem; color: var(--art-text-muted);">
                    Найдено: <strong>{{ $articles->total() }}</strong>
                    @if(request('q'))
                        по запросу «<strong>{{ request('q') }}</strong>»
                    @endif
                    @if(request('category') && ($activeCategory = $categories->firstWhere('slug', request('category'))))
                        в рубрике «<strong>{{ $activeCategory->name }}</strong>»
                    @endif
                </div>
            @endif

            <div class="articles-grid">
                @foreach($articles as $article)
                    <a href="{{ route('articles.show', $article->slug) }}" class="article-card" style="text-decoration: none; color: inherit;">
                        <div class="article-card__cover">
                            @if($article->cover_url)
                                <img src="{{ $article->cover_url }}" alt="{{ $article->title }}" loading="lazy">
                            @else
                                <div style="display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; background: linear-gradient(135deg, #1a1008, #2d1a09);">
                                    <span style="font-family: var(--art-serif); font-size: 3rem; color: rgba(255,255,255,.15);">संस्कृत</span>
                                </div>
                            @endif
                        </div>
                        <div class="article-card__body">
                            @if($article->category)
                                <span class="article-card__category">{{ $article->category->name }}</span>
                            @endif
                            <h3 class="article-card__title">{{ $article->title }}</h3>
                            @if($article->excerpt)
                                <p class="article-card__excerpt">{{ Str::limit($article->excerpt, 140) }}</p>
                            @endif
                            <div class="article-card__meta">
                                <span><i class="far fa-clock"></i>{{ $article->reading_time }} мин</span>
                                <span><i class="far fa-eye"></i>{{ number_format($article->views_count, 0, '.', ' ') }}</span>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>

            {{-- Пагинация --}}
            <div style="margin-top: 40px;">
                {{ $articles->withQueryString()->links() }}
            </div>
        @endif
    </div>

</div>

@endsection