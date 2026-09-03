<?php

declare(strict_types=1);

/*
 * H4001 (Wave 3 плана Telegram support leverage) — dense-нога FAQ-ретривала.
 *
 * Всё чтение идёт через этот файл: Ollama на GPU-узле Ивана доступна только
 * через sshd reverse-tunnel (`autossh -R 11434:localhost:11434`) на проде
 * `.92`, поэтому base_url — 127.0.0.1 на той машине, где выполняется код.
 * Туннель недоступен = KNOWLEDGE_EMBEDDING_DRIVER пуст → NullEmbeddingProvider,
 *_lane остаётся на BM25 (пол по контракту). Модели на `.92` НЕ ставим
 * (запрет #1633), студентский трафик через n8n `.91` не ходит.
 */

return [

    // '' → NullEmbeddingProvider (dense-ноги нет, BM25-идентичное поведение);
    // 'ollama' → OllamaEmbeddingProvider через туннель. Пустая строка вместо
    // null, чтобы inventory не помечал ключ required — отсутствие ключа и
    // есть штатное «dense-ноги нет».
    'driver' => env('KNOWLEDGE_EMBEDDING_DRIVER', ''),

    // База Ollama за туннелем. Слушает только на .92 (127.0.0.1:11434,
    // владелец сокета — sshd-session, см. EXPERIMENT_OLLAMA_GPU_OCT1_2026.md).
    'base_url' => env('KNOWLEDGE_OLLAMA_BASE_URL', 'http://127.0.0.1:11434'),

    // На узле модель видна ровно как bge-m3:latest (проба 01-09-2026) —
    // короткое имя без тега даёт 404 "model not found".
    'embedding_model' => env('KNOWLEDGE_EMBEDDING_MODEL', 'bge-m3:latest'),

    // H4002 (Wave 3b) переиспользует этот же файл для теневой генерации —
    // второго конфига не заводим.
    'generation_model' => env('KNOWLEDGE_GENERATION_MODEL', 'qwen3:14b'),

    // bge-m3 = 1024 float32 = 4096 байт на чанк.
    'dimensions' => (int) env('KNOWLEDGE_EMBEDDING_DIMENSIONS', 1024),

    // D2: 5 секунд, БЕЗ ретраев в request-path — медленный GPU-узел деградирует,
    // а не подвешивает вызвавшую сторону. Ретраи живут внутри Horizon-джобы.
    'timeout' => (int) env('KNOWLEDGE_REQUEST_TIMEOUT', 5),

    // Сколько чанков за один /api/embed вызов при индексации.
    'index_batch_size' => (int) env('KNOWLEDGE_INDEX_BATCH_SIZE', 16),

    'fusion' => [
        // Константа k в reciprocal rank fusion: score(d) = Σ 1/(k + rank_i(d)).
        // 60 — стандарт Okapi-литературы; выше k → мягче влияние dense-ноги.
        'k' => (int) env('KNOWLEDGE_FUSION_K', 60),
        // Глубина каждой ноги до слияния (dense leg fetch depth).
        'depth' => (int) env('KNOWLEDGE_RETRIEVAL_DEPTH', 20),
        // Веса ног в weighted RRF: score(d) = Σ w_leg / (k + rank_leg(d)).
        // Спарс-вес 1.0 фиксирован, dense 0.6 — подобрано на live-замере
        // H4001 (03-09-2026): при равных весах гибрид ронял MRR свежего
        // набора ниже BM25-пола (0.852 < 0.899), 0.6 возвращает пол.
        // Правило храповика: одна правка за коммит, ниже BM25 на любом
        // наборе = дефект, не тюнинг.
        'weight_sparse' => (float) env('KNOWLEDGE_FUSION_WEIGHT_SPARSE', 1.0),
        'weight_dense' => (float) env('KNOWLEDGE_FUSION_WEIGHT_DENSE', 0.5),
    ],

];
