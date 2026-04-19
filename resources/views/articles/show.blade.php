@extends('layouts.articles')

{{-- SEO: meta_title → заголовок страницы, fallback на title --}}
@section('title', ($article->meta_title ?: $article->title) . ' — ОРС')
@section('meta_description', $article->meta_description ?: $article->excerpt)

@section('og_type', 'article')
@section('og_title', $article->title)
@section('og_description', $article->excerpt)
@if($article->cover_url)
    @section('og_image', $article->cover_url)
@endif

{{-- JSON-LD для Google — помогает в выдаче --}}
@push('head')
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "Article",
    "headline": @json($article->title),
    "description": @json($article->excerpt),
    @if($article->cover_url)
    "image": @json($article->cover_url),
    @endif
    "datePublished": @json(optional($article->published_at)->toIso8601String()),
    "dateModified": @json($article->updated_at->toIso8601String()),
    "author": {
        "@type": "Organization",
        "name": @json($article->author_name)
    },
    "publisher": {
        "@type": "Organization",
        "name": "Общество ревнителей санскрита",
        "logo": {
            "@type": "ImageObject",
            "url": @json(asset('images/logo.png'))
        }
    }
}
</script>
@endpush

@section('content')

{{-- Прогресс-бар чтения — фиксирован сверху, растёт со скроллом --}}
<div class="reading-progress" id="readingProgress"></div>

{{-- ═══════════════ HERO ═══════════════ --}}
<section class="article-hero">
    <div class="article-hero__deva">संस्कृत</div>
    <div class="article-hero__inner">

        @if($article->category)
            <div class="article-hero__badge">{{ $article->category->name }}</div>
        @endif

        <h1 class="article-hero__title">
            {{ $article->title }}
            @if($article->subtitle)
                <br><em>{{ $article->subtitle }}</em>
            @endif
        </h1>

        @if($article->excerpt)
            <p class="article-hero__lead">{{ $article->excerpt }}</p>
        @endif

        <div class="article-hero__meta">
            <span class="article-hero__meta-item">
                <i class="far fa-clock"></i> {{ $article->reading_time }} мин чтения
            </span>
            <span class="article-hero__meta-item">
                <i class="fas fa-university"></i> {{ $article->author_name }}
            </span>
            @if($article->published_at)
                <span class="article-hero__meta-item">
                    <i class="far fa-calendar"></i> {{ $article->published_at->format('d.m.Y') }}
                </span>
            @endif
        </div>
    </div>
</section>

{{-- ═══════════════ КОНТЕНТ + TOC ═══════════════ --}}
<div class="article-layout">

    {{-- ОСНОВНОЙ КОНТЕНТ — рендерится как есть из БД --}}
    {{-- !! важно: используем {!! !!} осознанно, т.к. body пишут админы из доверенной админки --}}
    <main class="article-body">
        {!! $article->body !!}

        
    </main>

    {{-- САЙДБАР С TOC — заполнится JS из заголовков h2 --}}
    <aside class="article-sidebar" id="articleSidebar">
    <div class="toc-card">
        <div class="toc-card__title">Содержание</div>
        <ul class="toc-list" id="tocList">
            {{-- JS заполнит ниже --}}
        </ul>
    </div>
</aside>

</div>

@endsection

@push('scripts')
<script>
(function() {
    'use strict';

// ── ЦЕЛИ АНАЛИТИКИ ──

// Цель: 60 секунд на странице
setTimeout(function() {
    if (typeof window.sendGoal === 'function') {
        window.sendGoal('article_time_60s');
    }
}, 60000);

// Цель: доскроллил до 75% статьи
let scrollGoalSent = false;
window.addEventListener('scroll', function() {
    if (scrollGoalSent) return;
    const scrolled = window.scrollY + window.innerHeight;
    const total = document.documentElement.scrollHeight;
    if (total > 0 && (scrolled / total) >= 0.75) {
        scrollGoalSent = true;
        if (typeof window.sendGoal === 'function') {
            window.sendGoal('article_scroll_75');
        }
    }
}, { passive: true });

// Цель: открыл модалку записи
document.addEventListener('click', function(e) {
    const target = e.target.closest('[data-trial-trigger], .article-cta__btn, .header-cta');
    if (!target) return;
    if (typeof window.sendGoal === 'function') {
        window.sendGoal('article_trial_modal_open');
    }
});
    // ── Перехват кликов по CTA-кнопкам в теле статьи ──
// Открывает модалку записи вместо перехода на samskrtam.ru/ku
document.addEventListener('click', function(e) {
    const link = e.target.closest('.article-body a.article-cta__btn, .article-body a[href*="/ku"]');
    if (!link) return;

    e.preventDefault();
    
if (typeof window.sendGoal === 'function') {
    window.sendGoal('article_cta_click');
}
    // Триггерим модалку через Alpine — ищем body и обновляем стейт
    const bodyEl = document.body;
    if (bodyEl && typeof window.Alpine !== 'undefined') {
        // Через Alpine.$data() можно дёрнуть реактивный стейт
        const data = window.Alpine.$data(bodyEl);
        if (data) {
            data.isTrialModalOpen = true;
        }
    }
});
    // ── Прогресс-бар чтения ──
    const bar = document.getElementById('readingProgress');
    if (bar) {
        window.addEventListener('scroll', function() {
            const total = document.documentElement.scrollHeight - window.innerHeight;
            if (total <= 0) return;
            bar.style.width = (window.scrollY / total * 100) + '%';
        }, { passive: true });
    }

    // ── Fade-in по Intersection Observer ──
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(el) {
                if (el.isIntersecting) {
                    el.target.classList.add('visible');
                    observer.unobserve(el.target);
                }
            });
        }, { threshold: 0.08 });
        document.querySelectorAll('.fade-in').forEach(function(el) { observer.observe(el); });
    } else {
        document.querySelectorAll('.fade-in').forEach(function(el) { el.classList.add('visible'); });
    }

    // ── Автогенерация TOC + подсветка активного пункта ──
    const body = document.querySelector('.article-body');
    const tocList = document.getElementById('tocList');
    const tocAside = document.getElementById('articleSidebar');

    if (!body || !tocList || !tocAside) return;

    const headings = body.querySelectorAll('h2');

    if (headings.length < 2) {
        tocAside.style.display = 'none';
        return;
    }

    const items = [];
    headings.forEach(function(h, idx) {
        if (!h.id) {
            h.id = 's' + (idx + 1);
        }
        const li = document.createElement('li');
        const a = document.createElement('a');
        a.href = '#' + h.id;
        a.innerHTML = '<span class="toc-num">' + (idx + 1) + '</span>' + h.textContent.trim();
        li.appendChild(a);
        tocList.appendChild(li);
        items.push({ id: h.id, link: a, el: h });
    });

    // Хелпер — абсолютная позиция элемента от верха документа.
// offsetTop ломается, когда родитель имеет position:relative;
// getBoundingClientRect + scrollY всегда даёт корректное значение.
function getAbsoluteTop(el) {
    return el.getBoundingClientRect().top + window.scrollY;
}

function updateActiveToc() {
    const scrollPos = window.scrollY + 140;
    const docHeight = document.documentElement.scrollHeight;
    const viewHeight = window.innerHeight;

    // Дошли до низа страницы — активен последний пункт
    if (window.scrollY + viewHeight >= docHeight - 50) {
        items.forEach(function(item, idx) {
            item.link.classList.toggle('active', idx === items.length - 1);
        });
        return;
    }

    // Ещё до первого H2 — ничего не активно
    if (scrollPos < getAbsoluteTop(items[0].el)) {
        items.forEach(function(item) {
            item.link.classList.remove('active');
        });
        return;
    }

    // Обычная логика — последний H2 над линией скролла
    let activeIdx = -1;
    for (let i = 0; i < items.length; i++) {
        if (getAbsoluteTop(items[i].el) <= scrollPos) {
            activeIdx = i;
        } else {
            break;
        }
    }

    items.forEach(function(item, idx) {
        item.link.classList.toggle('active', idx === activeIdx);
    });
}

    window.addEventListener('scroll', updateActiveToc, { passive: true });
    window.addEventListener('hashchange', updateActiveToc);
    window.addEventListener('load', updateActiveToc);
    updateActiveToc();
})();
</script>
@endpush