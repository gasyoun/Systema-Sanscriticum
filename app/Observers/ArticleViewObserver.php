<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Article;
use App\Models\ArticleView;

class ArticleViewObserver
{
    /**
     * При создании события просмотра — инкрементим счётчик на статье.
     * Используем increment() — атомарная операция в БД, без race condition.
     */
    public function created(ArticleView $view): void
    {
        Article::whereKey($view->article_id)->increment('views_count');
    }

    /**
     * При удалении просмотра (на случай админской очистки) — декрементим.
     */
    public function deleted(ArticleView $view): void
    {
        Article::whereKey($view->article_id)
               ->where('views_count', '>', 0) // защита от ухода в минус
               ->decrement('views_count');
    }
}